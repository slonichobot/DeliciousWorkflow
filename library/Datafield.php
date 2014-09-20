<?php

/**
* Name: 		Datafield
* Description: 	This simple PHP class makes easy to store user's configuration information and load it swiftly
* Author: 		Simon Lomic (@slonichobot)
* Revised: 		9/5/2014
* Version:		1.0
*/

define("ITEM_SEP", "\n");
define("FIELD_SEP", ":");

include 'library/SqAES.php';

class Datafield {

	private $data;
	private $filename;
	private $folder;
	private $securekey;

	/**
	 * Description:
	 * Class constructor, sets class variables about config file location and loads data
	 * 
	 * @param $filename - name of the config file
	 * @param $folder - location of config file
	 * @param $encryptkey - If you're using encrypted fields, this will be used as a salt
	 */
	function __construct($folder, $filename, $encryptkey = '')
	{
		$this->filename = $filename;
		$this->folder = $folder;
	    $this->securekey = $encryptkey;
		if (file_exists($folder.$filename))
		{
			$f = fopen($folder.$filename, 'r');
			$this->data = $this->parse(fread($f,filesize($folder.$filename)));
			fclose($f);
		}
	}

	function __destruct()
	{
		$this->save();
	}

	/**
	 * 	Sets value to an item
	 * 	
	 * 	@param $item - name of item
	 * 	@param $value - value of item
	 * 	@return none
	 */
	public function set($item, $value)
	{
		$this->data[$item]=$value;
	}

	/**
	 * 	Gets value of an item
	 * 	
	 * 	@param $item - name of item
	 * 	@return - returns the value of item
	 */
	public function get($item)
	{
		if (isset($this->data[$item]))
			return $this->data[$item];
		else
			return false;
	}

	/**
	 * 	Gets value from plist, loads once
	 * 	
	 * 	@param $item - name of item
	 * 	@param $plist - path to plist file
	 * 	@return - returns the value of item
	 */
	public function getPlist($item, $plist)
	{
		if (isset($this->data[$item])){
			return $this->data[$item];
		} else {
			if ( file_exists( $plist ) ) {
				exec( 'defaults read "'. $plist .'" '.$item, $out );
				if ( $out != "" ) {
					$out = $out[0];
					$this->set($item, $out);
					return $out;
				}
			}
		}
		return false;
	}



	public function dump() {
		print_r($this->data);
	}


	/**
	 * Parses the file data
	 * 
	 * @param $data - Data to parse
	 * @return array of parsed data
	 */
	private function parse($str)
	{
		$data = array();
		if ($data=='') return array();
		$arr = explode(ITEM_SEP, $str);
		if (!is_array($arr)) return $data;
		foreach ($arr as $item) {
			$item = explode(FIELD_SEP, $item);
			if (count($item)<2) continue;
			if ($item[0][0]=='@')
			{
				// encrypted
				$item[1] = $this->decrypt($item[1]);
			}
			$data[$item[0]] = $item[1];
		}
		return $data;
	}

	/**
	 * Saves the data from $this->data to file, encrypts encrypted items (name starting with '@')
	 * 
	 * @return none
	 */
	public function save()
	{
		if ($this->data==array()) return ;
		$data='';
		foreach ($this->data as $name => $val) {
			if ($name[0]=='@') $val=$this->encrypt($val);
			$data.=$name.FIELD_SEP.$val.ITEM_SEP;
		}
		$f = fopen($this->folder.$this->filename, 'w');
		fwrite($f, $data);
		fclose($f);
	}

    private function encrypt($input)
    {
        return sqAES::crypt($this->securekey, $input);
    }

    private function decrypt($input)
    {
        return sqAES::decrypt($this->securekey, $input);
    }




}