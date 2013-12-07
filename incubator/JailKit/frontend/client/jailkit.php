<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) Laurent Declercq <l.declercq@nuxwin.com>
 * Copyright (C) Sascha Bay <info@space2place.de>
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
 * @subpackage  JailKit
 * @copyright   Laurent Declercq <l.declercq@nuxwin.com>
 * @copyright   Sascha Bay <info@space2place.de>
 * @author      Laurent Declercq <l.declercq@nuxwin.com>
 * @author      Sascha Bay <info@space2place.de>
 * @link        http://www.i-mscp.net i-MSCP Home Site
 * @license     http://www.gnu.org/licenses/gpl-2.0.html GPL v2
 */

/***********************************************************************************************************************
 * Functions
 */

/**
 * Add SSH user
 *
 * @param iMSCP_pTemplate $tpl
 * @return bool
 */
function jailkit_addSshUser($tpl)
{
	if (isset($_POST['ssh_login_name']) && isset($_POST['ssh_login_pass']) && isset($_POST['ssh_login_pass_confirm'])) {
		$loginUsername = 'jk_' . clean_input($_POST['ssh_login_name']);
		$loginPassword = clean_input($_POST['ssh_login_pass']);
		$loginPasswordConfirm = clean_input($_POST['ssh_login_pass_confirm']);
		$error = false;

		$stmt = exec_query('SELECT jailkit_id, max_logins FROM jailkit WHERE admin_id = ?', $_SESSION['user_id']);

		if ($stmt->rowCount()) {
			$jailkitId = $stmt->fields['jailkit_id'];
			$sshUserLimit = $stmt->fields['max_logins'];

			$stmt = exec_query(
				'SELECT COUNT(*) AS cnt FROM jailkit_login INNER JOIN jailkit USING(jailkit_id) WHERE admin_id = ?',
				$_SESSION['user_id']
			);

			$activatedLogins = $stmt->fields['cnt'];

			if ($sshUserLimit != '0' && $activatedLogins >= $sshUserLimit) {
				showBadRequestErrorPage();
				exit;
			} elseif (strlen($loginUsername) < 6) {
				set_page_message(tr('Username must be at least 6 characters long.'), 'error');
				$error = true;
			} elseif (strlen(clean_input($_POST['ssh_login_name'])) > 16) {
				set_page_message(tr("Username is too long (max. 16 characters)."), 'error');
				$error = true;
			} elseif ($loginPassword !== $loginPasswordConfirm) {
				set_page_message(tr('Passwords do not match.'), 'error');
				$error = true;
			} elseif (!preg_match("/^[a-z][-a-z0-9_]*$/", clean_input($_POST['ssh_login_name']))) {
				set_page_message(
					tr('Username must begin with a lower case letter, followed by lower case letters, digits, underscores, or dashes.'),
					'error'
				);
				$error = true;
			}

			if (!checkPasswordSyntax($loginPassword)) {
				$error = true;
			}

			if (!$error) {
				try {
					$loginPassword = cryptPasswordWithSalt($loginPassword, generateRandomSalt(true));

					exec_query(
						'
							INSERT INTO jailkit_login (
								jailkit_id, ssh_login_name, ssh_login_pass, jailkit_login_status
							) VALUES(
								?, ?, ?, ?
							)
						',
						array($jailkitId, $loginUsername, $loginPassword, 'toadd')
					);

					send_request();
					return true;
				} catch (iMSCP_Exception_Database $e) {
					if ($e->getCode() == 23000) { // Duplicate entries
						set_page_message(tr('SSH username already exist.'), 'error');
					}
				}
			}

			$tpl->assign(
				array(
					'JAILKIT_USERNAME' => tohtml($_POST['ssh_login_name']),
					'JAILKIT_DIALOG_OPEN' => 1
				)
			);

			return false;
		}
	}

	showBadRequestErrorPage();
	exit;
}

/**
 * Edit SSH user
 *
 * @param iMSCP_pTemplate $tpl
 * @param int $sshUserId SSH user unique identifier
 * @return bool
 */
function jailkit_editSshUser($tpl, $sshUserId)
{
	if (
		$sshUserId && isset($_POST['ssh_login_pass']) && isset($_POST['ssh_login_pass_confirm']) &&
		isset($_POST['ssh_login_name'])
	) {
		$loginPassword = clean_input($_POST['ssh_login_pass']);
		$loginPasswordConfirm = clean_input($_POST['ssh_login_pass_confirm']);
		$error = false;

		if ($loginPassword !== $loginPasswordConfirm) {
			set_page_message(tr('Passwords do not match.'), 'error');
			$error = true;
		} elseif (!checkPasswordSyntax($loginPassword)) {
			$error = true;
		}

		if (!$error) {
			$loginPassword = cryptPasswordWithSalt($loginPassword, generateRandomSalt(true));

			$stmt = exec_query(
				'
					UPDATE
						jailkit_login
					INNER JOIN
						jailkit USING (jailkit_id)
					SET
						ssh_login_pass = ?, jailkit_login_status = ?
					WHERE
						jailkit_login_id = ?
					AND
						admin_id = ?
				',
				array($loginPassword, 'tochange', $sshUserId, $_SESSION['user_id'])
			);

			if ($stmt->rowCount()) {
				send_request();
				return true;
			}
		} else {
			$tpl->assign(
				array(
					'JAILKIT_USERNAME' => tohtml($_POST['ssh_login_name']),
					'JAILKIT_DIALOG_OPEN' => 1
				)
			);

			return false;
		}
	}

	showBadRequestErrorPage();
	exit;
}

/**
 * Activate/Deactivate SSH user
 *
 * @param int $sshUserId SSH user unique identifier
 * @param string $action Action (activate|deactivate)
 * @return void
 */
function jailkit_changeSshUserStatus($sshUserId, $action)
{
	if ($sshUserId) {
		if ($action == 'activate') {
			$bindParams = array('0', 'tochange', $sshUserId, $_SESSION['user_id']);
		} else {
			$bindParams = array('1', 'tochange', $sshUserId, $_SESSION['user_id']);
		}

		$stmt = exec_query(
			'
				UPDATE
					jailkit_login
				INNER JOIN
					jailkit USING(jailkit_id)
				SET
					ssh_login_locked = ?,
					jailkit_login_status = ?
				WHERE
					jailkit_login_id = ?
				AND
					admin_id = ?
			',
			$bindParams
		);

		if ($stmt->rowCount()) {
			send_request();

			if ($action == 'activate') {
				set_page_message(tr('SSH user scheduled for activation.'), 'success');
			} else {
				set_page_message(tr('SSH user scheduled for deactivation.'), 'success');
			}

			return;
		}
	}

	showBadRequestErrorPage();
}

/**
 * Delete SSH user
 *
 * @param int $sshUserId SSH user unique identifier
 * @return bool
 */
function jailkit_deleteSshUser($sshUserId)
{
	if ($sshUserId) {
		$stmt = exec_query(
			'
				UPDATE
					jailkit_login
				INNER JOIN
					jailkit USING(jailkit_id)
				SET
					jailkit_login_status = ?
				WHERE
					jailkit_login_id = ?
				AND
					admin_id = ?
			',
			array('todelete', $sshUserId, $_SESSION['user_id'])
		);

		if ($stmt->rowCount()) {
			send_request();
			return true;
		}
	}

	showBadRequestErrorPage();
	exit;
}

/**
 * Get SSH user limit
 *
 * @param iMSCP_pTemplate $tpl
 * @return void
 */
function jailkit_getSshUserLimit($tpl)
{
	$stmt = exec_query(
		'SELECT COUNT(*) AS cnt FROM jailkit_login INNER JOIN jailkit USING(jailkit_id )WHERE admin_id = ?',
		$_SESSION['user_id']
	);
	$recordsCount = $stmt->fields['cnt'];

	$stmt = exec_query('SELECT max_logins FROM jailkit WHERE admin_id = ?', $_SESSION['user_id']);

	$tpl->assign(
		'TR_JAILKIT_LOGIN_AVAILABLE',
		tr(
			'SSH Users: %s of %s',
			$recordsCount,
			($stmt->fields['max_logins'] == 0) ? '<b>unlimited</b>' : $stmt->fields['max_logins']
		)
	);

	if ($stmt->fields['max_logins'] != 0 && $recordsCount >= $stmt->fields['max_logins']) {
		$tpl->assign('JAILKIT_ADD_BUTTON', '');
		set_page_message(tr('SSH user limit is reached.'), 'info');
	}
}

/**
 * Generate page
 *
 * @param $tpl iMSCP_pTemplate
 * @param iMSCP_Plugin_Manager $pluginManager
 * @return void
 */
function jailkit_generatePage($tpl)
{
	$stmt = exec_query(
		'
			SELECT
				jailkit_login_id, ssh_login_name, jailkit_login_status
			FROM
				jailkit_login
			INNER JOIN
				jailkit USING(jailkit_id)
			WHERE
				admin_id = ?
			ORDER BY
				ssh_login_name
		',
		$_SESSION['user_id']
	);

	if ($stmt->rowCount()) {
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		foreach ($rows as $row) {
			if ($row['jailkit_login_status'] == 'ok') {
				$statusIcon = 'ok';
				$tpl->assign(
					array(
						'TR_CHANGE_ACTION_TOOLTIP' => tr('Deactivate'),
						'TR_CHANGE_ALERT' => tr('Are you sure you want to deactivate this SSH user?'),
						'CHANGE_ACTION' => 'deactivate'

					)
				);
			} elseif ($row['jailkit_login_status'] == 'disabled') {
				$statusIcon = 'disabled';
				$tpl->assign(
					array(
						'TR_CHANGE_ACTION_TOOLTIP' => tr('Activate'),
						'TR_CHANGE_ALERT' => tr('Are you sure you want to activate this SSH user?'),
						'CHANGE_ACTION' => 'activate'
					)
				);
			} elseif (
				$row['jailkit_login_status'] == 'toadd' || $row['jailkit_login_status'] == 'tochange' ||
				$row['jailkit_login_status'] == 'todelete'
			) {
				$statusIcon = 'reload';
			} else {
				$statusIcon = 'error';
			}

			$tpl->assign(
				array(
					'JAILKIT_USER_NAME' => tohtml($row['ssh_login_name']),
					'JAILKIT_LOGIN_ID' => tohtml($row['jailkit_login_id']),
					'JAILKIT_LOGIN_STATUS' => tohtml(translate_dmn_status($row['jailkit_login_status'])),
					'STATUS_ICON' => $statusIcon
				)
			);

			if (!in_array($row['jailkit_login_status'], array('ok', 'disabled'))) {
				$tpl->assign(
					array(
						'JAILKIT_ACTION_STATUS_LINK' => '',
						'JAILKIT_ACTION_LINKS' => ''
					)
				);
				$tpl->parse('JAILKIT_ACTION_STATUS_STATIC', 'jailkit_action_status_static');
			} else {
				$tpl->assign('JAILKIT_ACTION_STATUS_STATIC', '');
				$tpl->parse('JAILKIT_ACTION_STATUS_LINK', 'jailkit_action_status_link');
				$tpl->parse('JAILKIT_ACTION_LINKS', 'jailkit_action_links');
			}

			$tpl->parse('JAILKIT_LOGIN_ITEM', '.jailkit_login_item');
		}
	} else {
		$tpl->assign('JAILKIT_LOGIN_LIST', '');
		set_page_message('No SSH user found.', 'info');
	};


	$tpl->assign(
		array(
			'ACTION' => isset($_POST['action']) ? $_POST['action'] : 'add',
			'LOGIN_ID' => isset($_POST['login_id']) ? $_POST['login_id'] : ''
		)
	);

	jailkit_getSshUserLimit($tpl);
}

/***********************************************************************************************************************
 * Main
 */

iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onClientScriptStart);

check_login('user');

$tpl = new iMSCP_pTemplate();
$tpl->define_dynamic(
	array(
		'layout' => 'shared/layouts/ui.tpl',
		'page' => '../../plugins/JailKit/frontend/client/jailkit.tpl',
		'page_message' => 'layout',
		'jailkit_login_list' => 'page',
		'jailkit_login_item' => 'jailkit_login_list',
		'jailkit_action_status_link' => 'jailkit_login_item',
		'jailkit_action_status_static' => 'jailkit_login_item',
		'jailkit_action_links' => 'jailkit_login_item',
		'jailkit_add_button' => 'page'
	)
);

$tpl->assign(
	array(
		'TR_PAGE_TITLE' => tr('Client / Domains - SSH Users'),
		'THEME_CHARSET' => tr('encoding'),
		'ISP_LOGO' => layout_getUserLogo(),
		'DATATABLE_TRANSLATIONS' => getDataTablesPluginTranslations(),
		'TR_DIALOG_ADD_TITLE' => tojs(tr('Add SSH User', true)),
		'TR_DIALOG_EDIT_TITLE' => tojs(tr('Edit SSH User', true)),
		'TR_JAILKIT_USERNAME' => tr('SSH username'),
		'TR_SSH_USERNAME' => tr('Username'),
		'TR_SSH_PASSWORD' => tr('Password'),
		'TR_SSH_PASSWORD_CONFIRM' => tr('Password confirmation'),
		'TR_JAILKIT_LOGIN_STATUS' => tr('Status'),
		'TR_JAILKIT_LOGIN_ACTIONS' => tr('Actions'),
		'TR_ADD_JAILKIT_LOGIN' => tr('Add SSH User'),
		'DELETE_LOGIN_ALERT' => tr('Are you sure you want to delete this SSH user?'),
		'DISABLE_LOGIN_ALERT' => tr('Are you sure you want to disable this SSH user?'),
		'TR_EDIT' => tr('Edit'),
		'TR_DELETE' => tr('Delete'),
		'TR_DIALOG_ADD' => tojs(tr('Add', true)),
		'TR_DIALOG_EDIT' => tojs(tr('Edit', true)),
		'TR_DIALOG_CANCEL' => tojs(tr('CANCEL', true)),
		'TR_CANCEL' => tr('Cancel'),
		'JAILKIT_DIALOG_OPEN' => 0,
		'JAILKIT_USERNAME' => '',
		'TR_UPDATE' => tr('Update')
	)
);

if (isset($_REQUEST['action'])) {
	$action = clean_input($_REQUEST['action']);

	if ($action == 'add') {
		if (jailkit_addSshUser($tpl)) {
			set_page_message(tr('SSH user scheduled for addition.'), 'success');
			redirectTo('ssh_users.php');
		}
	} elseif ($action == 'edit') {
		$sshUserId = (isset($_POST['login_id'])) ? clean_input($_POST['login_id']) : '';

		if (jailkit_editSshUser($tpl, $sshUserId)) {
			set_page_message(tr('SSH user scheduled for update.'), 'success');
			redirectTo('ssh_users.php');
		}
	} elseif ($action == 'activate' || $action == 'deactivate') {
		$sshUserId = (isset($_GET['login_id'])) ? clean_input($_GET['login_id']) : '';
		jailkit_changeSshUserStatus($sshUserId, $action);
		redirectTo('ssh_users.php');
	} elseif ($action == 'delete') {
		$sshUserId = (isset($_GET['login_id'])) ? clean_input($_GET['login_id']) : '';

		if (jailkit_deleteSshUser($sshUserId)) {
			set_page_message(tr('SSH user scheduled for deletion.'), 'success');
			redirectTo('ssh_users.php');
		}
	} else {
		showBadRequestErrorPage();
	}
}

generateNavigation($tpl);
jailkit_generatePage($tpl);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');

iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onClientScriptEnd, array('templateEngine' => $tpl));

$tpl->prnt();
