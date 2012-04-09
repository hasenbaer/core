<?php

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2012 Leo Feyer
 *
 * Formerly known as TYPOlight Open Source CMS.
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5.3
 * @copyright  Leo Feyer 2005-2012
 * @author     Leo Feyer <http://www.contao.org>
 * @package    Backend
 * @license    LGPL
 */


/**
 * Initialize the system
 */
define('TL_MODE', 'BE');
require_once '../system/initialize.php';


/**
 * Class DiffController
 *
 * Show the difference between two versions of a record.
 * @copyright  Leo Feyer 2005-2012
 * @author     Leo Feyer <http://www.contao.org>
 * @package    Controller
 */
class DiffController extends Backend
{

	/**
	 * Initialize the controller
	 * 
	 * 1. Import the user
	 * 2. Call the parent constructor
	 * 3. Authenticate the user
	 * 4. Load the language files
	 * DO NOT CHANGE THIS ORDER!
	 */
	public function __construct()
	{
		$this->import('BackendUser', 'User');
		parent::__construct();

		$this->User->authenticate();
		$this->loadLanguageFile('default');

		// Include the PhpDiff library
		require TL_ROOT . '/system/library/PhpDiff/Diff.php';
		require TL_ROOT . '/system/library/PhpDiff/Diff/Renderer/Html/Contao.php';
	}


	/**
	 * Run the controller
	 * @return void
	 */
	public function run()
	{
		$strBuffer = '';
		$arrVersions = array();
		$intTo = 0;
		$intFrom = 0;

		if (!$this->Input->get('table') || !$this->Input->get('pid'))
		{
			$strBuffer = 'Please provide the table name and PID';
		}
		else
		{
			$objVersions = $this->Database->prepare("SELECT * FROM tl_version WHERE pid=? AND fromTable=? ORDER BY version DESC")
										  ->execute($this->Input->get('pid'), $this->Input->get('table'));

			if ($objVersions->numRows < 1)
			{
				$strBuffer = 'There are no versions of ' . $this->Input->get('table') . '.id=' . $this->Input->get('pid');
			}
			else
			{
				$intIndex = 0;
				$from = array();

				// Store the versions and mark the active one
				while ($objVersions->next())
				{
					if ($objVersions->active)
					{
						$intIndex = $objVersions->version;
					}

					$arrVersions[$objVersions->version] = $objVersions->row();
					$arrVersions[$objVersions->version]['info'] = $GLOBALS['TL_LANG']['MSC']['version'].' '.$objVersions->version.' ('.$this->parseDate($GLOBALS['TL_CONFIG']['datimFormat'], $objVersions->tstamp).') '.$objVersions->username;
				}

				// To
				if ($this->Input->get('to') && isset($arrVersions[$this->Input->get('to')]))
				{
					$intTo = $this->Input->get('to');
					$to = deserialize($arrVersions[$this->Input->get('to')]['data']);
				}
				else
				{
					$intTo = $intIndex;
					$to = deserialize($arrVersions[$intTo]['data']);
				}

				// From
				if ($this->Input->get('from') && isset($arrVersions[$this->Input->get('from')]))
				{
					$intFrom = $this->Input->get('from');
					$from = deserialize($arrVersions[$this->Input->get('from')]['data']);
				}
				elseif ($intIndex > 1)
				{
					$intFrom = $intIndex-1;
					$from = deserialize($arrVersions[$intFrom]['data']);
				}

				$this->loadLanguageFile($this->Input->get('table'));
				$this->loadDataContainer($this->Input->get('table'));

				$arrFields = $GLOBALS['TL_DCA'][$this->Input->get('table')]['fields'];

				// Find the changed fields and highlight the changes
				foreach ($to as $k=>$v)
				{
					if ($from[$k] != $to[$k])
					{
						if (!isset($arrFields[$k]['inputType']) || $arrFields[$k]['inputType'] == 'password' || $arrFields[$k]['eval']['doNotShow'] || $arrFields[$k]['eval']['hideInput'])
						{
							continue;
						}

						// Convert serialized arrays into strings
						if (is_array(($tmp = deserialize($to[$k]))) && !is_array($to[$k]))
						{
							$to[$k] = $this->implode($tmp);
						}
						if (is_array(($tmp = deserialize($from[$k]))) && !is_array($from[$k]))
						{
							$from[$k] = $this->implode($tmp);
						}
						unset($tmp);

						// Convert strings into arrays
						if (!is_array($to[$k]))
						{
							$to[$k] = explode("\n", $to[$k]);
						}
						if (!is_array($from[$k]))
						{
							$from[$k] = explode("\n", $from[$k]);
						}

						$objDiff = new \Diff($from[$k], $to[$k]);
						$strBuffer .= $objDiff->Render(new Diff_Renderer_Html_Contao(array('field'=>($arrFields[$k]['label'][0] ?: $k))));
					}
				}
			}
		}

		$this->Template = new BackendTemplate('be_diff');

		// Template variables
		$this->Template->content = $strBuffer;
		$this->Template->versions = $arrVersions;
		$this->Template->to = $intTo;
		$this->Template->from = $intFrom;
		$this->Template->fromLabel = 'Von';
		$this->Template->toLabel = 'Zu';
		$this->Template->showLabel = specialchars($GLOBALS['TL_LANG']['MSC']['showDifferences']);
		$this->Template->table = $this->Input->get('table');
		$this->Template->pid = intval($this->Input->get('pid'));
		$this->Template->theme = $this->getTheme();
		$this->Template->base = $this->Environment->base;
		$this->Template->language = $GLOBALS['TL_LANGUAGE'];
		$this->Template->title = $GLOBALS['TL_CONFIG']['websiteTitle'];
		$this->Template->charset = $GLOBALS['TL_CONFIG']['characterSet'];
		$this->Template->action = ampersand($this->Environment->request);

		$this->Template->output();
	}


	/**
	 * Implode a multi-dimensional array recursively
	 * @param mixed
	 * @return string
	 */
	protected function implode($var)
	{
		if (!is_array($var))
		{
			return $var;
		}
		elseif (!is_array(next($var)))
		{
			return implode(', ', $var);
		}
		else
		{
			$buffer = '';

			foreach ($var as $k=>$v)
			{
				$buffer .= $k . ": " . $this->implode($v) . "\n";
			}

			return trim($buffer);
		}
	}
}


/**
 * Instantiate the controller
 */
$objDiff = new DiffController();
$objDiff->run();
