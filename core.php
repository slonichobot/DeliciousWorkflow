<?php
/**
* Name: 		Delicious Workflow for Alfred 2
* Author: 		Šimon Lomič (@slonichobot)
* Revised: 		9/2/2014
* Version:		1.0
*/

define("URLPREF", "https://");
define("SITEURL", "api.del.icio.us/v1/");

define("BOOKMARKFILE", "data/bookmarks.json");
define("STATEDATAFILE", "data/statedata");

define("APPICON", "icon");
define("TAGICON", "img/tag");

define("USERAGENT", "Mozilla/5.0 (X11; Linux x86_64; rv:12.0) Gecko/20100101 Firefox/21.0");
define("HEADER", "Accept: text/xml,application/xml,application/xhtml+xml,text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5");
define("KEEPALIVE", "Keep-Alive: 300");

define("LASTTIME_UPDATE_LIMIT", 6);
define("RESULT_TAG_LIMIT", 6);
define("RESULT_LIMIT", 50);

define("UID_PREFIX", "");

include("library/helperFunctions.php");
require_once('library/Workflows.php');
require_once('library/Delicious.php');

$d=new Delicious(new Workflows, $argv);
$d->index();
