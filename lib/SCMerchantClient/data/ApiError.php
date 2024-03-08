<?php

defined('_JEXEC') or die('Restricted access');

	/**
	 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
	 */

class ApiError
{
	private $code;
	private $message;

	/**
	 * @param $code
	 * @param $message
	 */
	function __construct($code, $message)
	{
		$this->code = $code;
		$this->message = $message;
	}

	/**
	 * @return Integer
	 */
	public function getCode()
	{
		return $this->code;
	}

	/**
	 * @return String
	 */
	public function getMessage()
	{
		return $this->message;
	}


}