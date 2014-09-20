<?php

require_once('library/XML2Array.php');

define ("BOOKMARK_UPLIMIT", 100000000);

/**
* Name: 		Delicious
* Description: 	This PHP class object wraps the Alfred 2 workflow function for Delicious.com
* Author: 		Simon Lomic (@slonichobot)
* Revised: 		9/3/2014
* Version:		1.0
*/
class Delicious {

	private $w;
	private $checksum;
	private $error;
	private $argv;
	private $return_counter;
	private $just_synced;

	private $TAGS;
	private $DATA;

	/**
	* Description:
	* Class constructor function. Intializes all class variables.
	*
	* @param $w - Workflows class instance for common workflows actions
	* @param $argv - arguments taken from Alfred prompt
	* @return none
	*/
	function __construct( Workflows $w, $argv )
	{
		$this->w = $w;

		$this->parseArguments($argv);

		$this->return_counter = 1;

		// Do I Have Even Set Up Credentials?
		if ((!isset($this->argv[0]) || ($this->argv[0] != ':signin:' && $this->argv[0] != ':update:')) 
				&&
		 	($this->getItem('loginstatus') != 1))
		{
			$this->error=1;
			$this->loginmessage($this->getItem('loginstatus'));
		}

	}

	/**
	* Description:
	* Class destructor function. Saves the time() information to know the latency between requests.
	*
	* @return none
	*/
	function __destruct()
	{
		if (!$this->error) $this->setItem ("lasttime", time() );
		echo $this->w->toxml();
	}


	/**
	* Description:
	* Login function, place when you set up your credentials.
	* The accuracy of your credentials is find out by simple /v1/post/get request
	* which obtains last bookmark by its side effect.
	*
	* @param $w - Workflows class instance for common workflows actions
	* @param $argv - arguments taken from Alfred prompt
	* @return none
	*/
	public function login() {
		if (count($this->argv)>2 && $this->argv[0]==':signin:')
		{
			$this->setItem('loginname',$this->argv[1]);
			$this->setItem('@passwd',$this->argv[2]);
			if ($this->request("posts/get", 0)) {
				$this->setItem('loginstatus',1);
				echo "You are successfully logged in";
				exit;
			} else {
				$this->setItem('loginstatus',-1);
				echo "Wrong username or password.\nPlease try again.";
				$this->error=1;
				exit;
			}
		}
	}

	/**
	* Description:
	* Shows message that you need to login
	*
	* @param $status - Status code [-1] means bad credentials
	* @return none
	*/
	public function loginmessage($status) {
		if ($status == -1) {
			$this->result("Wrong username or password", "Type [dllogin] to login", "");
		}
		else
		{
			$this->result("Login required", "Type [dllogin] to login", "");
		}
	}

	/**
	* Description:
	* Update method updates the local stored database of bookmarks by overwriting it with the actual one
	*
	* @param $show - bool whether to show sync status
	* @return none
	*/
	public function update($show=1) {
		// Fetch All Bookmarks
		$ret = $this->request("posts/all?meta=yes&tag_separator=comma");
		if (!isset($this->new_checksum)) 
			$checksumTXT = '';
		// Parse Update Data
		$arr = XML2Array::createArray($this->stripCode($ret));
		$this->DATA = array();
		$this->TAGS = array();
		foreach ($arr['posts']['post'] as $post) {
			$tags = explode(',', $post["@attributes"]["tag"]);
			sort($tags);
			$this->DATA[] = (object) [
				"url" => $post["@attributes"]["href"],
				"tags" => $tags,
				"desc" => $post["@attributes"]["description"],
				"hash" => $post["@attributes"]["hash"]
			];

			foreach ($tags as $tag) {
				$tag = $this->transformTag($tag);
				$this->TAGS[$tag][] = &$this->DATA[count($this->DATA)-1];
			}
			if (!isset($this->new_checksum))
				$checksumTXT .= $post["@attributes"]["meta"];
		}
		// Create Checksum, if not already
		if (!isset($this->new_checksum)) $this->new_checksum = md5($checksumTXT);

		// Write Data
		$this->w->write( $this->DATA, BOOKMARKFILE);

		// Set Checksum about this update
		$this->setItem ("checksum", $this->new_checksum);

		if ($show==1) 
			$this->result("Synced Successfully", "( ".count($this->DATA)." items, ".count($this->TAGS)." tags )");
		else
			echo "Synced Successfully ( ".count($this->DATA)." items, ".count($this->TAGS)." tags )";

	}


	/**
	* Description:
	* Index is the core of application.
	*
	* @return none
	*/
	public function index()
	{
		if (isset($this->argv[0]) && $this->argv[0] == ':signin:')
		{
			$this->login();
			return ;
		}
		if (count($this->argv)==1 && $this->argv[0]==':update:') {
			try {
				$this->update(2);
			} catch (Exception $e) {
				$this->error = 1;
				if ($code = $e->getCode() == -1)
					echo "Connection error occured\nPlease try again later, or contact developer";
				else {
					$this->setItem('loginstatus', -1);
					echo "Wrong username or password.\nPlease login again.";
				}
				return ;
			}
			return ;
		}
		if ($this->getItem('loginstatus') != 1) return ;
		try {
			if ($this->syncState())
				$this->update();
			else
				$this->loadData();

			// do what you are commanded
			$this->readArguments();

		} catch ( Exception $e ) {
			if ($code = $e->getCode() == -1)
				$this->connection_message();
			elseif ($e->getCode() == -2)
				$this->login();
		}
	}

	/**
	 * Description:
	 * parses arguments send in argv as a string (becouse of special chars like '#') into array
	 * @param $argv - array of arguments from commandline
	 * @return none
	 */
	private function parseArguments($argv)
	{
		if (!isset($argv[1]) || !is_string($argv[1])) { $this->argv = array(); return ; }
		$argv[1] = preg_replace('/ +/', ' ', $argv[1]);
		if ($argv[1]==' ') { $this->argv = array(); return ; }
		$this->argv = explode(' ', $argv[1]);
		foreach ($this->argv as $key => $value) {
			if (preg_match('/^ *$/', $value)) unset($this->argv[$key]);
		}
	}

	/**
	* Description:
	* Reads arguments passed from command line and calls appropriate methods
	* 
	* If first arg matches tag, next arguments matches inside tag and last arguments don't, are used as search query in matched tags
	* 
	* If first argument partly matches tag, list of matched tags is printed, next arguments are used to part match as well, but the whole sentence is used to fulltext search on all items, if is there space to display them
	* 
	* If first argument doesn't matches any tag, the whole sentence is used to fulltext search on all items
	* 
	*
	* @return none
	*/
	public function readArguments()
	{
		$acttags=array();
		$tagfound = 0;
		$tagfound_arr = array();
		$tagfound_arr_std = array();
		$tagnofound_arr = array();
		$run = 0;
		$state = 0;
		$firsttagmatch = '';
		$firstfulltextarg = 0;
		$onlytags = 0;
		foreach ($this->argv as $arg)
		{
			if ($arg=='#') { $run++; $onlytags = 1; continue; }
			$act_tagfound=0;
			$act_tagpartfound=0;
			foreach ($this->TAGS as $tag => $bookmarks)
			{
				$acttag=strtolower($tag);
				$actarg=strtolower($arg);
				if (($pos = strpos($acttag, $actarg)) !== false)
				{
					$act_tagpartfound=1;
					if ($state==0 && $acttag == $actarg)
					{
						$tagfound = $act_tagfound = 1;
						$tagfound_arr[$tag] = 1;
						$tagfound_arr_std[] = $tag;
						$firsttagmatch = $tag;
					} else {
						$acttags[$tag]=count($bookmarks);
						if ($pos === 0)  $acttags[$tag] += BOOKMARK_UPLIMIT; // put match from beginning on top
					}
				}
			}
			if (!$tagfound && count($acttags) == 0) { $state=3; break; } // state 3: no tag even partly match, let's full text
			if ($state==0 && !$act_tagfound) { $state=1; $firstfulltextarg = $run; } // state 1: now no exact match
			if (($state==0 || $state==1) && !$act_tagfound && !$act_tagpartfound) 
			{
				if ($state == 0) $firstfulltextarg = $run;
				$state=2; // state 2: some tags found, than only full text
			}
			if (!$act_tagfound) $tagnofound_arr[$arg] = 1;
			$run++;
		}
		$cnt=0;
		if ($tagfound) {
			// some args are exact tags
			$displayarr = array();
			if (count($tagfound_arr) > 1) {
				// more exact tags, make intersection
				foreach ($this->TAGS[$firsttagmatch] as $bookmark) {
					if (count($tagfound_arr) == count(array_intersect($bookmark->tags, $tagfound_arr_std)))
					{
						// same amount of same tags as searched tags
						$displayarr[] = $bookmark;
					}
				}
			} else $displayarr = $this->TAGS[$firsttagmatch];
			
			
			// List other tags which have items with this tag(s):
			$tags_displayarr = array();
			$first_displayarr = array();
			foreach ($displayarr as $key => $bookmark) {
				$found = 1;
				if ($state!=2) {
					foreach ($bookmark->tags as $tag) {
						$tag = $this->transformTag($tag);
						if (isset($tagfound_arr[$tag])) continue; // not the found ones
						if ($state==1 && !isset($acttags[$tag])) { $found=0; continue; } // tags filtering
						if (!isset($tags_displayarr[$tag])) 
							if ($acttags[$tag] >= BOOKMARK_UPLIMIT) $tags_displayarr[$tag]=BOOKMARK_UPLIMIT9; else $tags_displayarr[$tag]=1;
						else
							$tags_displayarr[$tag]++;
					}
				} else { $found=0; }
				if ($found==0 && ($state==1 || $state==2) && !$onlytags)
				{
					// filter fulltext:
					if (($stat = $this->fulltextByTags( $firstfulltextarg, $bookmark )) == 0) 
					{
						unset($displayarr[$key]);
					} else {
						if ($stat == 2) // match on beginning
						{
							$first_displayarr[] = $displayarr[$key];
							unset($displayarr[$key]);
						}
					}
				}
			}
			arsort($tags_displayarr);
			$this->draw_tags($tags_displayarr, $tagfound_arr);

			// Draw items with those tags
			if (!$onlytags) $this->draw_items(array_merge($first_displayarr, $displayarr));

		} else {
			if (count($acttags)!=0) {
				// partly matched tags
				arsort($acttags);
				$this->draw_tags($acttags);
			} elseif($onlytags) {
				// no match tags, but show only tags -> showing all
				foreach ($this->TAGS as $key => $value) {
					$plus = 0;
					 $key = $this->transformTag($key);
					 $acttags[$key]=count($value)+$plus;
				}
				arsort($acttags);
				$this->draw_tags($acttags, array(), 1);
			}

			if (!$onlytags) {
				// Fulltext all items:
				$itemcnt = 0;
				$first_displayarr = array();
				foreach ($this->DATA as $key => $bookmark) {
					if (($stat = $this->fulltextByTags( $firstfulltextarg, $bookmark )) == 0) 
					{
						unset($this->DATA[$key]);
					} else {
						$itemcnt++;
						if ($stat == 2) // match on beginning
						{	
							$first_displayarr[] = $this->DATA[$key];
							unset($this->DATA[$key]);
						}
					}

					if (($itemcnt+1) > RESULT_LIMIT) break;
				}
				$this->DATA = array_merge($first_displayarr, $this->DATA);
				$this->draw_items($this->DATA);
			}
		}
	}

	/**
	 * Description:
	 * Helper method which determines if particular bookmark is filtered by string arguments.
	 * 
	 * @param $first_tag - Index of first argument in $argv which shall be used. All other till end are used as well.
	 * @param $bookmark - Bookmark which I am looking on
	 * @return [1] when bookmark matches. [0] when not. [2] when matches the beggining of url
	 */
	private function fulltextByTags( $first_tag, $bookmark )
	{
		$until = count($this->argv);
		for ( $i = $first_tag; $i < $until; $i++ ) {
			$searched = strtolower( $this->argv[$i] );
			$pos = (strpos(strtolower(cleanurl($bookmark->url)), $searched));
			if (($pos === false)
				&& (strpos(strtolower($bookmark->url), $searched)) === false
				&& (strpos(strtolower($bookmark->desc), $searched)) === false)
			{
				return 0; // unset
			}
			if ($pos===0) {
				return 2;
			}
		}
		return 1;
	}

	/**
	 * Description:
	 * Print array of tags in Alfred window
	 * 
	 * @param $tags - array of tags in format {tag} => {sorting value}
	 * @return none
	 */
	private function draw_tags($tags, $args=array(), $all=0)
	{
		$tagslist='';
		foreach ($args as $key => $value) {
			$tagslist.=$key.' ';
		}
		foreach ($tags as $tag => $val) {
			if (is_array($val)) $count = count($val);
			else {
				if ($val > BOOKMARK_UPLIMIT) $count = $val-BOOKMARK_UPLIMIT;
				else $count = $val;
			}
			$this->result( $tag, "(".$count.")", $tagslist . $tag . " ", $arg, TAGICON );
			$cnt++;
			if (!$all && $cnt > RESULT_TAG_LIMIT)
				break;
		}
	}

	/**
	 * Description:
	 * Print array of items in Alfred window
	 * 
	 * @param $items - array of items
	 * @return none
	 */
	private function draw_items($items)	
	{
		$until = ((count($items)>RESULT_LIMIT)?RESULT_LIMIT:count($items));
		for ($i=0; $i<$until; $i++) {
			if (!$items[$i]) {
				if (($i+1)>=count($items)) break;
				$until++;
				continue;
			}
			$this->showBookmark($items[$i]);
		}
	}

	/**
	* Description:
	* syncState determines in which state of synchronisation with Delicious.com you are and finds out
	* if you need an immediate update.
	*
	* @return bool whether you need to update
	*/
	public function syncState() {
		$stat = 0;
		if ((time() - $this->getItem('lasttime')) < LASTTIME_UPDATE_LIMIT )
			return 0;
		if ( ($this->getItem('lasttime') > 0) ) 
		{
			// Some Data are Already Stored, look at Checksum
			$checksum_DATA = $this->request("posts/all?hashes");
			$old_checksum = $this->getItem("checksum");
			$this->new_checksum = $this->calculateChecksum($this->stripCode($checksum_DATA));
			if ( $old_checksum != $this->new_checksum ) 
			{
				// checksum mismatch
				return 1;
			}
		} else {
			// No Data Stored, download them
			return 1;
		}
		return 0;
	}

	/**
	* Description:
	* loadData loads bookmarks database from JSON file into PHP array
	*
	* @return none
	*/
	public function loadData()
	{
		$this->DATA = array();
		$this->TAGS = array();
		$this->DATA = $this->w->read( BOOKMARKFILE );
		if (is_array($this->DATA)) {
			foreach ($this->DATA as $key => $post)
			{
				if (is_array($post->tags)) 
				{
					$newtags=array();
					foreach ($post->tags as $tag) 
					{
						$tag = $this->transformTag($tag);
						$newtags[] = $tag;
						$this->TAGS[$tag][] = &$this->DATA[$key];
					}
					$this->DATA[$key]->tags=$newtags;
				}		
			}	
		} else {
			$this->error = 1;
		}
	}

	/**
	 * Description
	 * connection_message displays message about connection error
	 * 
	 * @param $code - Code of http error that has occurred.
	 * @return none
	 */
	public function connection_message($code=null)
	{
		$this->result("Connection error occured", "Please try again later, or contact developer", ' ');
		if ($code) $this->result("", "HTTP Error number: ".$code, ' ');
	}

	// ----------

	/**
	* Description:
	* Calculates checksum of file from /v1/post/... file.
	* Attribute meta required!!!
	*
	* @return MD5 checksum from all bookmarks hashes
	*/
	private function calculateChecksum( $data ) {
		$arr = XML2Array::createArray($data);
		$checksumTXT = '';
		foreach ($arr['posts']['post'] as $post) {
			$checksumTXT .= $post["@attributes"]["meta"];
		}
		return md5($checksumTXT);
	}

	/**
	 * Description:
	 * Helper for Workflow result method, which adds result line into Alfred window (generated in destructor)
	 * 
	 * @param $title - Title of result line
	 * @param $sub  - Subtitle of result line
	 * @param query - Query parameter, used only when $nextstep is not null. Passed to output in Alfred Workflow as {query}
	 * @param icon - Used icon in result line. Default is icon.png, the main icon of workflow
	 * @param next_step - Alfred command you are redirected on when ENTER key is pressed. The query argument is then unused.
	 * @return none
	 */
	private function result($title, $sub = null, $next_step = null, $query = null, $icon = APPICON ) {
		$this->w->result( 
			UID_PREFIX . $this->return_counter++,
			$query, 
			$title,
			$sub,
			$icon . '.png',
			(($query !== null)?'yes':'no'), // sets result to be invalid, so then next_step can be autocompleted
			(($next_step)?$next_step:'') );
	}

	/**
	 * Description:
	 * Requests here are returned in {CODE}{CONTENT} format. The CODE is three digits of HTTP code. 
	 * This method strips them off.
	 * 
	 * @param $data - Received data
	 * @return The content of HTTTP request without HTTP code.
	 */
	private function stripCode($data) {
		return substr($data,3);
	}

	/**
	 * Description:
	 * Analyses requests in {CODE}{CONTENT} format. The CODE is three digits of HTTP code. 
	 * 
	 * @param $data - Received data
	 * @return [1] for 200 code. [-1] for 500 and 999 code which means i am banned. [-2] for 401 bad credentials code.
	 */
	private function checkRequestCode($data) {
		$code = substr($data,0,3);
		if (is_numeric($code)) {
			if ($code == 500 || $code == 999)
				return -1;
			if ($code == 401)
				return -2;
			if ($code == 200)
				return 1;
		} else return 0;
		return 1;
	}


	/**
	 * Description:
	 * Sets item state in plist file as well as this class property
	 * 
	 * @param $item - Item name
	 * @param $value - Item value
	 * @return none
	 */
	private function setItem($item, $value) {
		$this->w->set ($item, $value );
	}

	/**
	 * Description:
	 * Retrieves item state from plist file, stores for later call
	 * 
	 * @param $item - Item name
	 * @return none
	 */
	private function getItem($item) {
		return $this->w->get($item);
	}

	/**
	 * Description:
	 * HTTP Request helper function
	 * 
	 * @param $url - URL you want to visit
	 * @param $exc - bool, whether throw an exception. If false, bool returned
	 * @return Requested data in format: {HTTP CODE}{DATA}
	 */
	private function request($url, $exc=1) {
		$data = $this->w->request ( URLPREF . $this->getItem('loginname') . ":" . $this->getItem('@passwd') . "@" . SITEURL . $url, array(
			CURLOPT_HTTPHEADER	=>	array(HEADER,KEEPALIVE),
			CURLOPT_USERAGENT	=>	USERAGENT
		) );
		if (($code = $this->checkRequestCode($data)) != 1) {
			if (!$exc) return 0;
			throw new Exception("Error Processing Request", $code);
		}
		if (!$exc) return 1;
		return $data;
	}


	/**
	 * Description:
	 * Formats bookmark into Alfred window
	 * 
	 * @param $bookmark - Bookmark object
	 * @return none
	 */
	private function showBookmark($bookmark) {
		$tags='';
		if (count($bookmark->tags)) {
			foreach ($bookmark->tags as $tag) {
				if ($tag!='') $tags.="[$tag] ";
			}
			$tags.=' ';
		}
		$this->result( cleanurl($bookmark->url), $tags.$bookmark->desc, $bookmark->url, $bookmark->url );
	}

	private function transformTag($tag) {
		$tag = strtolower($tag);
		if ($tag=='') $tag="untagged";
		return $tag;
	}
}