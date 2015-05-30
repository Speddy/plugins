<?php
/**
 * i-MSCP InstantSSH plugin
 * Copyright (C) 2014-2015 Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 */

namespace InstantSSH\Admin;

use Crypt_RSA as CryptRsa;
use iMSCP_Events as Events;
use iMSCP_Events_Aggregator as EventManager;
use iMSCP_Exception_Database as ExceptionDatabase;
use iMSCP_Plugin_Manager as PluginManager;
use iMSCP_pTemplate as TemplateEngnine;
use iMSCP_Registry as Registry;
use InstantSSH\CommonFunctions as Functions;
use InstantSSH\Validate\SshAuthOptions as SshAuthOptions;

/***********************************************************************************************************************
 * Functions
 */

/**
 * Get openSSH key and its associated fingerprint
 *
 * @param string $rsaKey RSA key (Supported formats: PKCS#1, openSSH and XML Signature)
 * @return array|false An array which contain the normalized SSH key and its associated fingerprint or false on failure
 */
function getOpenSshKey($rsaKey)
{
	$rsa = new CryptRsa();
	$ret = false;

	if($rsa->loadKey($rsaKey)) {
		$ret = array();
		$rsa->setPublicKey();
		$ret['key'] = $rsa->getPublicKey(CRYPT_RSA_PUBLIC_FORMAT_OPENSSH);
		$content = explode(' ', $ret['key'], 3);
		$ret['fingerprint'] = join(':', str_split(md5(base64_decode($content[1])), 2));
	}

	return $ret;
}

/**
 * Get SSH user
 *
 * @return void
 */
function getSshUser()
{
	if(isset($_GET['ssh_user_id']) && isset($_GET['ssh_user_name'])) {
		$sshUserId = intval($_GET['ssh_user_id']);
		$sshUserName = clean_input($_GET['ssh_user_name']);

		try {
			$stmt = exec_query(
				'SELECT * FROM instant_ssh_users WHERE ssh_user_admin_id = ? AND ssh_user_id = ?',
				array($_SESSION['user_id'], $sshUserId)
			);

			if($stmt->rowCount()) {
				Functions::sendJsonResponse(200, $stmt->fetchRow(\PDO::FETCH_ASSOC));
			}
		} catch(ExceptionDatabase $e) {
			write_log(sprintf('InstantSSH: Unable to %s SSH user: %s', $sshUserName, $e->getMessage()), E_USER_ERROR);
			Functions::sendJsonResponse(
				500, array('message' => tr('An unexpected error occurred. Please contact your reseller.'))
			);
		}
	}

	Functions::sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/**
 * Add/Update SSH user
 *
 * @param PluginManager $pluginManager
 * @param array $sshPermissions SSH permissions
 * @return void
 */
function addSshUser($pluginManager, $sshPermissions)
{
	if(isset($_POST['ssh_user_id']) && isset($_POST['ssh_user_name'])) {
		$sshUserId = intval($_POST['ssh_user_id']);
		$sshUserName = clean_input($_POST['ssh_user_name']);
		$sshUserPassword = $sshUserPasswordConfirmation = null;
		$sshUserKey = clean_input($_POST['ssh_user_key']);
		$sshUserKeyFingerprint = '';

		/** @var \iMSCP_Plugin_InstantSSH $plugin */
		$plugin = $pluginManager->pluginGet('InstantSSH');

		if(!$plugin->getConfigParam('passwordless_authentication', false)) {
			if(isset($_POST['ssh_user_password']) && isset($_POST['ssh_user_cpassword'])) {
				$sshUserPassword = clean_input($_POST['ssh_user_password']);
				$sshUserPasswordConfirmation = clean_input($_POST['ssh_user_cpassword']);
			} else {
				Functions::sendJsonResponse(400, array('message' => tr('Bad request.')));
			}
		}

		$sshAuthOptions = $plugin->getConfigParam('default_ssh_auth_options', null);
		$errorMsgs = array();

		if($sshPermissions['ssh_permission_auth_options']) {
			if(isset($_POST['ssh_user_auth_options']) && is_string($_POST['ssh_user_auth_options'])) {
				$sshAuthOptions = clean_input($_POST['ssh_user_auth_options']);

				if($sshAuthOptions !== '') {
					$sshAuthOptions = str_replace(array("\r\n", "\r", "\n"), '', $sshAuthOptions);
					$allowedAuthOptions = $plugin->getConfigParam('allowed_ssh_auth_options', array());
					$validator = new SshAuthOptions(array('auth_option' => $allowedAuthOptions));

					if(!$validator->isValid($sshAuthOptions)) {
						$errorMsgs[] = implode('<br>', $validator->getMessages());
					}
				} else {
					$sshAuthOptions = null;
				}
			} else {
				Functions::sendJsonResponse(400, array('message' => tr('Bad request.')));
			}
		}

		if(!$sshUserId) {
			if($sshUserName === '') {
				$errorMsgs[] = tr('The username field is required.');
			} elseif(!preg_match('/^[[:alnum:]]+$/i', $sshUserName)) {
				$errorMsgs[] = tr('Un-allowed username. Please use alphanumeric characters only.');
			} elseif(strlen($sshUserName) > 8) {
				$errorMsgs[] = tr('The username is too long (Max 8 characters).');
			}

			$sshUserName = $plugin->getConfigParam('ssh_user_name_prefix', 'imscp_') . $sshUserName;

			if(posix_getpwnam($sshUserName)) {
				$errorMsgs[] = tr('This username is not available.');
			}
		}

		if($sshUserPassword === '' && $sshUserKey === '') {
			if($plugin->getConfigParam('passwordless_authentication', false)) {
				$errorMsgs[] = tr('You must enter an SSH key.');
			} else {
				$errorMsgs[] = tr('You must enter either a password, an SSH key or both.');
			}
		}

		if($sshUserPassword !== '') {
			if(preg_match('/[^\x21-\x7e]/', $sshUserPassword)) {
				$errorMsgs[] = tr('Un-allowed password. Please use ASCII characters only.');
			} elseif (strlen($sshUserPassword) < 8) {
				$errorMsgs[] = tr('Wrong password length (Min 8 characters).');
			} elseif(strlen($sshUserPassword) > 32) {
				$errorMsgs[] = tr('Wrong password length (Max 32 characters).');
			} elseif($sshUserPassword !== $sshUserPasswordConfirmation) {
				$errorMsgs[] = tr('Passwords do not match.');
			}
		}

		if($sshUserKey !== '') {
			if(($sshUserKey = getOpenSshKey($sshUserKey)) === false) {
				$errorMsgs[] = tr('Invalid SSH key.');
			} else {
				$sshUserKeyFingerprint = $sshUserKey['fingerprint'];
				$sshUserKey = $sshUserKey['key'];
			}
		} else {
			$sshUserKey = $sshAuthOptions = $sshUserKeyFingerprint = null;
		}

		if($errorMsgs) {
			Functions::sendJsonResponse(400, array('message' => implode('<br>', $errorMsgs)));
		}

		if($sshUserPassword != '') {
			$sshUserPassword = cryptPasswordWithSalt($sshUserPassword, generateRandomSalt(true));
		} else {
			$sshUserPassword = null;
		}

		try {
			if(!$sshUserId) { // Add SSH user
				if(
					$sshPermissions['ssh_permission_max_users'] == 0 ||
					$sshPermissions['ssh_permission_cnb_users'] < $sshPermissions['ssh_permission_max_users']
				) {
					$response = EventManager::getInstance()->dispatch('onBeforeAddSshUser', array(
						'ssh_user_permission_id' => $sshPermissions['ssh_permission_id'],
						'ssh_user_admin_id' => $_SESSION['user_id'],
						'ssh_user_name' => $sshUserName,
						'ssh_user_password' => $sshUserPassword,
						'ssh_user_key' => $sshUserKey,
						'ssh_user_key_fingerprint' => $sshUserKeyFingerprint,
						'ssh_user_auth_options' => $sshAuthOptions
					));

					if(!$response->isStopped()) {
						exec_query(
							'
							INSERT INTO instant_ssh_users (
								ssh_user_permission_id, ssh_user_admin_id, ssh_user_name, ssh_user_password,
								ssh_user_key, ssh_user_key_fingerprint, ssh_user_auth_options, ssh_user_status
							) VALUES (
								?, ?, ?, ?, ?, ?, ?, ?
							)
						',
							array(
								$sshPermissions['ssh_permission_id'], $_SESSION['user_id'], $sshUserName, $sshUserPassword,
								$sshUserKey, $sshUserKeyFingerprint, $sshAuthOptions, 'toadd'
							)
						);

						send_request();
						write_log(
							sprintf(
								'InstantSSH: %s added new SSH user: %s',
								decode_idna($_SESSION['user_logged']),
								$sshUserName
							),
							E_USER_NOTICE
						);
						Functions::sendJsonResponse(
							200, array('message' => tr('SSH user has been scheduled for addition. Please note that creating your SSH environment can take several minutes.'))
						);
					} else {
						Functions::sendJsonResponse(
							500, array('message' => tr('The action has been stopped by another plugin.'))
						);
					}
				} else {
					Functions::sendJsonResponse(
						400, array('message' => tr('Your SSH user limit is reached.'))
					);
				}
			} else { // Update SSH user
				$response = EventManager::getInstance()->dispatch('onBeforeUpdateSshUser', array(
					'ssh_user_permission_id' => $sshPermissions['ssh_permission_id'],
					'ssh_user_admin_id' => $_SESSION['user_id'],
					'ssh_user_name' => $sshUserName,
					'ssh_user_password' => $sshUserPassword,
					'ssh_user_key' => $sshUserKey,
					'ssh_user_key_fingerprint' => $sshUserKeyFingerprint,
					'ssh_user_auth_options' => $sshAuthOptions
				));

				if(!$response->isStopped()) {
					exec_query(
						'
						UPDATE
							instant_ssh_users
						SET
							ssh_user_password = ?, ssh_user_key = ?, ssh_user_key_fingerprint = ?,
							ssh_user_auth_options = ?, ssh_user_status = ?
						WHERE
							ssh_user_id = ?
						AND
							ssh_user_admin_id = ?
						AND
							ssh_user_status = ?
					',
						array(
							$sshUserPassword, $sshUserKey, $sshUserKeyFingerprint, $sshAuthOptions, 'tochange',
							$sshUserId, $_SESSION['user_id'], 'ok'
						)
					);

					send_request();
					write_log(
						sprintf(
							'InstantSSH: %s updated SSH user: %s', decode_idna($_SESSION['user_logged']), $sshUserName),
						E_USER_NOTICE
					);
					Functions::sendJsonResponse(
						200, array('message' => tr('SSH user has been scheduled for update.'))
					);
				} else {
					Functions::sendJsonResponse(
						500, array('message' => tr('The action has been stopped by another plugin.'))
					);
				}
			}
		} catch(ExceptionDatabase $e) {
			if($e->getCode() == '23000') {
				Functions::sendJsonResponse(
					400, array('message' => tr("An SSH user with the same name or the same SSH key already exists."))
				);
			} else {
				write_log(
					sprintf('InstantSSH: Unable to add or update the %s SSH user: %s', $sshUserName, $e->getMessage()),
					E_USER_ERROR
				);
				Functions::sendJsonResponse(
					500, array('message' => tr('An unexpected error occurred. Please contact your reseller.'))
				);
			}
		}
	}

	Functions::sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/**
 * Delete SSH user
 *
 * @return void
 */
function deleteSshUser()
{
	if(isset($_POST['ssh_user_id']) && isset($_POST['ssh_user_name'])) {
		$sshUserId = intval($_POST['ssh_user_id']);
		$sshUserName = clean_input($_POST['ssh_user_name']);

		$response = EventManager::getInstance()->dispatch('onBeforeDeleteSshUser', array(
			'ssh_user_id' => $sshUserId,
			'ssh_user_name' => $sshUserName
		));

		if(!$response->isStopped()) {
			try {
				$stmt = exec_query(
					'UPDATE instant_ssh_users SET ssh_user_status = ? WHERE ssh_user_id = ? AND ssh_user_admin_id = ?',
					array('todelete', $sshUserId, $_SESSION['user_id'])
				);

				if($stmt->rowCount()) {
					EventManager::getInstance()->dispatch('onAfterDeleteSshUser', array(
						'ssh_user_id' => $sshUserId,
						'ssh_user_name' => $sshUserName
					));

					send_request();
					write_log(
						sprintf(
							'InstantSSH: %s deleted an SSH user: %s',
							decode_idna($_SESSION['user_logged']),
							$sshUserName
						),
						E_USER_NOTICE
					);
					Functions::sendJsonResponse(
						200, array('message' => tr('SSH user has been scheduled for deletion.'))
					);
				}
			} catch(ExceptionDatabase $e) {
				write_log(
					sprintf('InstantSSH: Unable to delete the %s SSH user: %s', $sshUserName, $e->getMessage()),
					E_USER_ERROR
				);
				Functions::sendJsonResponse(
					500, array('message' => tr('An unexpected error occurred. Please contact your reseller.'))
				);
			}
		} else {
			Functions::sendJsonResponse(
				500, array('message' => tr('The action has been stopped by another plugin.'))
			);
		}
	}

	Functions::sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/**
 * Get SSH users list
 *
 * @return void
 */
function getSshUsers()
{
	try {
		// Filterable / orderable columns
		$cols = array('ssh_user_name', 'ssh_user_key_fingerprint', 'ssh_user_status');
		$nbCols = count($cols);
		$idxCol = 'ssh_user_id';
		/* DB table to use */
		$table = 'instant_ssh_users';

		/* Paging */
		$limit = '';
		if(isset($_GET['iDisplayStart']) && isset($_GET['iDisplayLength']) && $_GET['iDisplayLength'] !== '-1') {
			$limit = 'LIMIT ' . intval($_GET['iDisplayStart']) . ', ' . intval($_GET['iDisplayLength']);
		}

		/* Ordering */
		$order = '';
		if(isset($_GET['iSortCol_0']) && isset($_GET['iSortingCols'])) {
			$order = 'ORDER BY ';

			for($i = 0; $i < intval($_GET['iSortingCols']); $i++) {
				if($_GET['bSortable_' . intval($_GET['iSortCol_' . $i])] === 'true') {
					$sortDir = (
						isset($_GET["sSortDir_$i"]) && in_array($_GET["sSortDir_$i"], array('asc', 'desc'))
					) ? $_GET["sSortDir_$i"] : 'asc';

					$order .= $cols[intval($_GET["iSortCol_$i"])] . ' ' . $sortDir . ', ';
				}
			}

			$order = substr_replace($order, '', -2);

			if($order == 'ORDER BY') {
				$order = '';
			}
		}

		/* Filtering */
		$where = 'WHERE ssh_user_admin_id = ' . intval($_SESSION['user_id']);
		if(isset($_GET['sSearch']) && $_GET['sSearch'] != '') {
			$where .= ' AND (';

			for($i = 0; $i < $nbCols; $i++) {
				$where .= $cols[$i] . ' LIKE ' . quoteValue('%' . $_GET['sSearch'] . '%') . ' OR ';
			}

			$where = substr_replace($where, '', -3);
			$where .= ')';
		}

		/* Individual column filtering */
		for($i = 0; $i < $nbCols; $i++) {
			if(isset($_GET["bSearchable_$i"]) && $_GET["bSearchable_$i"] === 'true' && $_GET["sSearch_$i"] !== '') {
				$where .= "AND {$cols[$i]} LIKE " . quoteValue('%' . $_GET["sSearch_$i"] . '%');
			}
		}

		/* Get data to display */
		$rResult = execute_query(
			'
				SELECT
					SQL_CALC_FOUND_ROWS ' . str_replace(' , ', ' ', implode(', ', $cols)) . ",
					ssh_user_id
				FROM
					$table
				$where
				$order
				$limit
			"
		);

		/* Data set length after filtering */
		$resultFilterTotal = execute_query('SELECT FOUND_ROWS()');
		$resultFilterTotal = $resultFilterTotal->fetchRow(\PDO::FETCH_NUM);
		$filteredTotal = $resultFilterTotal[0];

		/* Total data set length */
		$resultTotal = exec_query(
			"SELECT COUNT($idxCol) FROM $table WHERE ssh_user_admin_id = ?", $_SESSION['user_id']
		);
		$resultTotal = $resultTotal->fetchRow(\PDO::FETCH_NUM);
		$total = $resultTotal[0];

		/* Output */
		$output = array(
			'sEcho' => intval($_GET['sEcho']),
			'iTotalRecords' => $total,
			'iTotalDisplayRecords' => $filteredTotal,
			'aaData' => array()
		);

		$trEditTooltip = tr('Edit SSH user');
		$trDeleteTooltip = tr('Delete this SSH user');

		while($data = $rResult->fetchRow(\PDO::FETCH_ASSOC)) {
			$row = array();

			for($i = 0; $i < $nbCols; $i++) {
				if($cols[$i] == 'ssh_user_key_fingerprint') {
					$row[$cols[$i]] = ($data[$cols[$i]]) ?: tr('n/a');
				} elseif($cols[$i] == 'ssh_user_status') {
					$row[$cols[$i]] = translate_dmn_status($data[$cols[$i]]);
				} else {
					$row[$cols[$i]] = tohtml($data[$cols[$i]]);
				}
			}

			if($data['ssh_user_status'] == 'ok') {
				$row['ssh_user_actions'] =
					"<span title=\"$trEditTooltip\" data-action=\"edit_ssh_user\" " . "data-ssh-user-id=\"" .
					$data['ssh_user_id'] . "\" data-ssh-user-name=\"" . $data['ssh_user_name'] .
					"\" class=\"icon icon_edit clickable\">&nbsp;</span> "
					.
					"<span title=\"$trDeleteTooltip\" data-action=\"delete_ssh_user\" " . "data-ssh-user-id=\"" .
					$data['ssh_user_id'] . "\" data-ssh-user-name=\"" . $data['ssh_user_name'] .
					"\" class=\"icon icon_delete clickable\">&nbsp;</span>";
			} else {
				$row['ssh_user_actions'] = '';
			}

			$output['aaData'][] = $row;
		}

		Functions::sendJsonResponse(200, $output);
	} catch(ExceptionDatabase $e) {
		write_log(sprintf('InstantSSH: Unable to get SSH users: %s', $e->getMessage()), E_USER_ERROR);
		Functions::sendJsonResponse(
			500, array('message' => tr('An unexpected error occurred. Please contact your reseller.'))
		);
	}

	Functions::sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/***********************************************************************************************************************
 * Main
 */

EventManager::getInstance()->dispatch(Events::onClientScriptStart);
check_login('user');

/** @var PluginManager $pluginManager */
$pluginManager = Registry::get('pluginManager');

/** @var \iMSCP_Plugin_InstantSSH $plugin */
$plugin = $pluginManager->pluginGet('InstantSSH');
$sshPermissions = $plugin->getCustomerPermissions($_SESSION['user_id']);

if($sshPermissions['ssh_permission_id'] !== null) {
	if(isset($_REQUEST['action'])) {
		if(is_xhr()) {
			$action = clean_input($_REQUEST['action']);

			switch($action) {
				case 'get_ssh_users':
					getSshUsers();
					break;
				case 'get_ssh_user':
					getSshUser();
					break;
				case 'add_ssh_user':
					addSshUser($pluginManager, $sshPermissions);
					break;
				case 'delete_ssh_user':
					deleteSshUser();
					break;
				default:
					Functions::sendJsonResponse(400, array('message' => tr('Bad request.')));
			}
		}

		showBadRequestErrorPage();
	}

	$tpl = new TemplateEngnine();
	$tpl->define_dynamic(array('layout' => 'shared/layouts/ui.tpl', 'page_message' => 'layout'));
	$tpl->define_no_file_dynamic(array(
		'page' => Functions::renderTpl(
			$pluginManager->pluginGetDirectory() . '/InstantSSH/themes/default/view/client/ssh_users.tpl'
		),
		'ssh_password_field_block' => 'page',
		'ssh_auth_options_block' => 'page',
		'ssh_password_key_info_block' => 'page'
	));

	if(Registry::get('config')->DEBUG) {
		$assetVersion = time();
	} else {
		$pluginInfo = $pluginManager->pluginGetInfo('InstantSSH');
		$assetVersion = strtotime($pluginInfo['date']);
	}

	EventManager::getInstance()->registerListener('onGetJsTranslations', function ($e) {
		/** @var $e \iMSCP_Events_Event */
		$e->getParam('translations')->InstantSSH = array(
			'dataTable' => getDataTablesPluginTranslations(false)
		);
	});

	$tpl->assign(array(
		'TR_PAGE_TITLE' => Functions::escapeHtml(tr('Client / Web Tools / SSH Users')),
		'INSTANT_SSH_ASSET_VERSION' => Functions::escapeUrl($assetVersion),
		'DEFAULT_AUTH_OPTIONS' => $plugin->getConfigParam('default_ssh_auth_options', ''),
		'SSH_USERNAME_PREFIX' => $plugin->getConfigParam('ssh_user_name_prefix', 'imscp_'),
		'PAGE_MESSAGE' => '' // Remove default message HTML element (not used here)
	));

	if(!$sshPermissions['ssh_permission_auth_options']) {
		$tpl->assign('SSH_AUTH_OPTIONS_BLOCK', '');
	} else {
		$allowedSshAuthOptions = $plugin->getConfigParam('allowed_ssh_auth_options');
		$tpl->assign(
			'TR_ALLOWED_OPTIONS', Functions::escapeHtml(
				tr('Allowed authentication options: %s', implode(', ', $allowedSshAuthOptions))
			)
		);
	}

	if($plugin->getConfigParam('passwordless_authentication', false)) {
		$tpl->assign(array('SSH_PASSWORD_KEY_INFO_BLOCK' => '', 'SSH_PASSWORD_FIELD_BLOCK' => ''));
	}

	generateNavigation($tpl);

	$tpl->parse('LAYOUT_CONTENT', 'page');
	EventManager::getInstance()->dispatch(Events::onClientScriptEnd, array('templateEngine' => $tpl));
	$tpl->prnt();
} else {
	showBadRequestErrorPage();
}
