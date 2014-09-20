<?php

function cleanurl( $url ) {
	$url = substr($url, strpos($url, '/')+2);
	if (substr($url,0,4)=="www.")
		$url=substr($url,4);
	if ($url{strlen($url)-1}=='/')
		$url=substr($url,0,strlen($url)-1);
	return $url;
}
