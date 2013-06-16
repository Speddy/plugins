<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2013 by i-MSCP Team
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * @category    iMSCP
 * @package     iMSCP_Plugin
 * @subpackage  Mailman
 * @copyright   2010-2013 by i-MSCP Team
 * @author      Laurent Declercq <l.declercq@nuxwin.com>
 * @link        http://www.i-mscp.net i-MSCP Home Site
 * @license     http://www.gnu.org/licenses/gpl-2.0.html GPL v2
 */

/**
 * Mailman Plugin.
 *
 *
 * @category    iMSCP
 * @package     iMSCP_Plugin
 * @subpackage  Mailman
 * @author      Laurent Declercq <l.declercq@nuxwin.com>
 */
class iMSCP_Plugin_Mailman extends iMSCP_Plugin_Action
{

	/**
	 * @var array
	 */
	protected $routes = array();

	/**
	 * Register a callback for the given event(s).
	 *
	 * @param iMSCP_Events_Manager_Interface $controller
	 */
	public function register(iMSCP_Events_Manager_Interface $controller)
	{
		$controller->registerListener(iMSCP_Events::onClientScriptStart, $this);

		$this->routes = array(
			'/client/mailman.php' => PLUGINS_PATH . '/' . $this->getName() . '/client/mailman.php'
		);
	}

	/**
	 * Implements the onAdminScriptStart event
	 *
	 * @return void
	 */
	public function onClientScriptStart()
	{
		$this->injectMailmanLinks();

		if(isset($_REQUEST['plugin']) && $_REQUEST['plugin'] == 'mailman') {
			$this->handleRequest();
		}
	}

	/**
	 * Get routes
	 *
	 * @return array
	 */
	public function getRoutes()
	{
		return $this->routes;
	}

	/**
	 * Inject Mailman links into the navigation object
	 */
	protected function injectMailmanLinks()
	{
		/** @var Zend_Navigation $navigation */
		$navigation = iMSCP_Registry::get('navigation');

		if (($page = $navigation->findOneBy('uri', '/client/mail_accounts.php'))) {
			$page->addPage(
				array(
					'label' => tohtml('E-Mail Lists'),
					'uri' => '/client/mailman.php',
					'title_class' => 'plugin'
				)
			);
		}
	}

	/**
	 * Handle Mailman plugin requests
	 */
	protected function handleRequest()
	{
		if(isset($_REQUEST['plugin']) && $_REQUEST['plugin'] == 'mailman') {
			// Load mailman action script
			require_once PLUGINS_PATH . '/Mailman/admin/mailman.php';
			exit;
		}
	}
}
