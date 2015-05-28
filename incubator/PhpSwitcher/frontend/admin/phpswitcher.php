<?php
/**
 * i-MSCP PhpSwitcher plugin
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

namespace PhpSwitcher;

use iMSCP_Database as Database;
use iMSCP_Events as Events;
use iMSCP_Events_Aggregator as EventManager;
use iMSCP_Exception_Database as DatabaseException;
use iMSCP_Plugin_Manager as PluginManager;
use iMSCP_Plugin_PhpSwitcher as PhpSwitcher;
use iMSCP_pTemplate as TemplateEngine;
use iMSCP_Registry as Registry;
use PDO;

/***********************************************************************************************************************
 * Functions
 */

/**
 * Send Json response
 *
 * @param int $statusCode HTTPD status code
 * @param array $data JSON data
 * @return void
 */
function sendJsonResponse($statusCode = 200, array $data = array())
{
	header('Cache-Control: no-cache, must-revalidate');
	header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
	header('Content-type: application/json');

	switch ($statusCode) {
		case 202:
			header('Status: 202 Accepted');
			break;
		case 400:
			header('Status: 400 Bad Request');
			break;
		case 404:
			header('Status: 404 Not Found');
			break;
		case 500:
			header('Status: 500 Internal Server Error');
			break;
		case 501:
			header('Status: 501 Not Implemented');
			break;
		default:
			header('Status: 200 OK');
	}

	echo json_encode($data);
	exit;
}

/**
 * Get PHP version
 *
 * @return void
 */
function get()
{
	if (isset($_GET['version_id']) && isset($_GET['version_name'])) {
		$versionId = intval($_REQUEST['version_id']);
		$versionName = clean_input($_REQUEST['version_name']);

		try {
			$stmt = exec_query('SELECT * FROM php_switcher_version WHERE version_id = ?', $versionId);

			if ($stmt->rowCount()) {
				sendJsonResponse(200, $stmt->fetchRow(PDO::FETCH_ASSOC));
			}

			sendJsonResponse(404, array('message' => tr('PHP version %s has not been found.', $versionName)));
		} catch (DatabaseException $e) {
			sendJsonResponse(500, array('message' => tr('An unexpected error occurred: %s', $e->getMessage())));
		}
	}

	sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/**
 * Add PHP version
 *
 * @return void
 */
function add()
{
	if (isset($_POST['version_name']) && isset($_POST['version_binary_path'])) {
		$versionName = clean_input($_POST['version_name']);
		$versionBinaryPath = clean_input($_POST['version_binary_path']);

		if ($versionName == '' || $versionBinaryPath == '') {
			sendJsonResponse(400, array('message' => tr('All fields are required.')));
		} elseif (
			strtolower($versionName) == 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION
		) {
			sendJsonResponse(400, array('message' => tr('PHP version %s already exists.', $versionName)));
		}

		$versionBinaryPath = _normalizePath($versionBinaryPath);

		try {
			exec_query(
				'INSERT INTO php_switcher_version (version_name, version_binary_path, version_status) VALUES (?, ?, ?)',
				array($versionName, $versionBinaryPath, 'toadd')
			);

			write_log(sprintf('PHP version %s has been added.', $versionName), E_USER_NOTICE);
			send_request();
			sendJsonResponse(200, array('message' => tr('PHP version %s has been scheduled for addition.', $versionName)));
		} catch (DatabaseException $e) {
			if ($e->getCode() == '23000') {
				sendJsonResponse(400, array('message' => tr('PHP version %s already exists.', $versionName)));
			} else {
				sendJsonResponse(500, array('message' => tr('An unexpected error occurred: %s', $e->getMessage())));
			}
		}
	}

	sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/**
 * Edit PHP version
 *
 * @return void
 */
function edit()
{
	if (isset($_POST['version_id']) && isset($_POST['version_name']) && isset($_POST['version_binary_path'])) {
		$versionId = intval($_POST['version_id']);
		$versionName = clean_input($_POST['version_name']);
		$versionBinaryPath = clean_input($_POST['version_binary_path']);

		if ($versionName == '' || $versionBinaryPath == '') {
			sendJsonResponse(400, array('message' => tr('All fields are required.')));
		} elseif (
			strtolower($versionName) == 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION
		) {
			sendJsonResponse(400, array(
				'message' => tr('PHP version %s already exists. This is the default PHP version.', $versionName)
			));
		}

		$versionBinaryPath = _normalizePath($versionBinaryPath);

		$stmt = exec_query(
			'SELECT version_name, version_binary_path FROM php_switcher_version WHERE version_id = ?', $versionId
		);
		$row = $stmt->fetchRow(PDO::FETCH_ASSOC);

		if ($row['version_name'] != $versionName || $row['version_binary_path'] != $versionBinaryPath) {
			$db = Database::getRawInstance();

			try {
				$db->beginTransaction();

				// Run backend side only if needed
				if ($row['version_binary_path'] != $versionBinaryPath) {
					exec_query(
						'
						UPDATE
							php_switcher_version
						SET
							version_name = ?, version_binary_path = ?, version_status = ?
						WHERE
							version_id = ?
					',
						array($versionName, $versionBinaryPath, 'tochange', $versionId)
					);

					$stmt = exec_query(
						'SELECT domain_name, domain_type FROM php_switcher_version_admin WHERE version_id = ?',
						$versionId
					);

					/** @var PluginManager $pluginManager */
					$pluginManager = Registry::get('pluginManager');

					/** @var PhpSwitcher $plugin */
					$plugin = $pluginManager->pluginGet('PhpSwitcher');

					if ($stmt->rowCount()) {
						$plugin->scheduleDomainsChange($stmt->fetchAll(PDO::FETCH_KEY_PAIR));
					}

					$db->commit();

					send_request();
					write_log(sprintf('PHP version %s has been updated.', $versionName), E_USER_NOTICE);
					sendJsonResponse(200, array(
						'message' => tr('PHP version %s successfully scheduled for update.', $versionName)
					));
				} else {
					exec_query(
						'UPDATE php_switcher_version SET version_name = ? WHERE version_id = ?',
						array($versionName, $versionId)
					);

					$db->commit();

					write_log(sprintf('PHP version %s has been updated.', $versionName), E_USER_NOTICE);
					sendJsonResponse(200, array(
						'message' => tr('PHP version %s successfully updated.', $versionName)
					));
				}
			} catch (DatabaseException $e) {
				$db->rollBack();

				if ($e->getCode() == '23000') {
					sendJsonResponse(400, array('message' => tr('PHP version %s already exists.', $versionName)));
				} else {
					sendJsonResponse(
						500, array('message' => tr('An unexpected error occurred: %s', $e->getMessage())
					));
				}
			}
		} else {
			sendJsonResponse(202, array('message' => tr('Nothing has been changed.')));
		}
	}

	sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/**
 * Delete PHP version
 *
 * @return void
 */
function delete()
{
	if (isset($_POST['version_id']) && isset($_POST['version_name'])) {
		$versionId = intval(clean_input($_POST['version_id']));
		$versionName = clean_input($_POST['version_name']);

		$db = Database::getRawInstance();

		try {
			$db->beginTransaction();

			$stmt = exec_query(
				'SELECT domain_name, domain_type FROM php_switcher_version_admin WHERE version_id = ?', $versionId
			);

			/** @var PluginManager $pluginManager */
			$pluginManager = Registry::get('pluginManager');

			/** @var PhpSwitcher $plugin */
			$plugin = $pluginManager->pluginGet('PhpSwitcher');

			if ($stmt->rowCount()) {
				$plugin->scheduleDomainsChange($stmt->fetchAll(PDO::FETCH_KEY_PAIR));
			}

			$stmt = exec_query('DELETE FROM php_switcher_version WHERE version_id = ?', $versionId);

			if ($stmt->rowCount()) {
				$db->commit();

				send_request();
				write_log(sprintf('PHP version %s has been scheduled for deletion.', $versionName), E_USER_NOTICE);
				sendJsonResponse(200, array(
					'message' => tr('PHP version %s successfully scheduled for deletion.', $versionName)
				));
			}
		} catch (DatabaseException $e) {
			$db->rollBack();
			sendJsonResponse(500, array('message' => tr('An unexpected error occurred: %s', $e->getMessage())));
		}
	}

	sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/**
 * Re-generate all PHP info files
 *
 * @throws DatabaseException
 * @return void
 */
function generatePhpInfo()
{
	$stmt = exec_query(
		'UPDATE php_switcher_version SET version_status = ? WHERE version_status = ?', array('tochange', 'ok')
	);

	if ($stmt->rowCount()) {
		send_request();
		write_log('PHP info files were scheduled for re-generation.', E_USER_NOTICE);
		sendJsonResponse(200, array('message' => tr('PHP info files were successfully scheduled for re-generation.')));
	}

	sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/**
 * Get table data
 *
 * @return void
 */
function getTable()
{
	try {
		$columns = array('version_id', 'version_name', 'version_binary_path', 'version_status');
		$nbColumns = count($columns);

		$indexColumn = 'version_id';

		/* DB table to use */
		$table = 'php_switcher_version';

		/* Paging */
		$limit = '';

		if (isset($_GET['iDisplayStart']) && $_GET['iDisplayLength'] != '-1') {
			$limit = 'LIMIT ' . intval($_GET['iDisplayStart']) . ', ' . intval($_GET['iDisplayLength']);
		}

		/* Ordering */
		$order = '';

		if (isset($_GET['iSortCol_0'])) {
			$order = 'ORDER BY ';

			for ($i = 0; $i < intval($_GET['iSortingCols']); $i++) {
				if ($_GET['bSortable_' . intval($_GET['iSortCol_' . $i])] == 'true') {
					$order .= $columns[intval($_GET['iSortCol_' . $i])] . ' ' . $_GET['sSortDir_' . $i] . ', ';
				}
			}

			$order = substr_replace($order, '', -2);

			if ($order == 'ORDER BY') {
				$order = '';
			}
		}

		/* Filtering */
		$where = '';

		if ($_REQUEST['sSearch'] != '') {
			$where .= 'WHERE (';

			for ($i = 0; $i < $nbColumns; $i++) {
				$where .= $columns[$i] . ' LIKE ' . quoteValue("%{$_GET['sSearch']}%") . ' OR ';
			}

			$where = substr_replace($where, '', -3);
			$where .= ')';
		}

		/* Individual column filtering */
		for ($i = 0; $i < $nbColumns; $i++) {
			if (isset($_GET["bSearchable_$i"]) && $_GET["bSearchable_$i"] == 'true' && $_GET["sSearch_$i"] != '') {
				$where .= "AND {$columns[$i]} LIKE " . quoteValue("%{$_GET["sSearch_$i"]}%");
			}
		}

		/* Get data to display */
		$rResult = execute_query(
			'
				SELECT SQL_CALC_FOUND_ROWS ' . str_replace(' , ', ' ', implode(', ', $columns)) . "
				FROM $table $where $order $limit
			"
		);

		/* Data set length after filtering */
		$resultFilterTotal = execute_query('SELECT FOUND_ROWS()');
		$resultFilterTotal = $resultFilterTotal->fetchRow(PDO::FETCH_NUM);
		$filteredTotal = $resultFilterTotal[0];

		/* Total data set length */
		$resultTotal = execute_query("SELECT COUNT($indexColumn) FROM $table");
		$resultTotal = $resultTotal->fetchRow(PDO::FETCH_NUM);
		$total = $resultTotal[0];

		/* Output */
		$output = array(
			'sEcho' => intval($_GET['sEcho']),
			'iTotalRecords' => $total,
			'iTotalDisplayRecords' => $filteredTotal,
			'aaData' => array()
		);

		$trEditTooltip = tr('Edit this PHP version');
		$trDeleteTooltip = tr('Delete this PHP version');

		while ($data = $rResult->fetchRow(PDO::FETCH_ASSOC)) {
			$row = array();

			for ($i = 0; $i < $nbColumns; $i++) {
				if ($columns[$i] == 'version_status') {
					if(in_array($data[$columns[$i]], array('ok', 'toadd', 'tochange', 'todelete'))) {
						$row[$columns[$i]] = translate_dmn_status($data[$columns[$i]]);
					} else {
						$row[$columns[$i]] = tohtml($data[$columns[$i]]);
					}
				} elseif ($columns[$i] != 'version_id') {
					$row[$columns[$i]] = $data[$columns[$i]];
				}
			}

			if (!in_array($data['version_status'], array('toadd','tochange'))) {
				$row['actions'] =
					"<span title=\"$trEditTooltip\" data-action=\"edit\" " .
					"data-version-id=\"{$data['version_id']}\" data-version-name=\"{$data['version_name']}\" " .
					"class=\"icon i_edit clickable\">&nbsp;</span> "
					.
					"<span title=\"$trDeleteTooltip\" data-action=\"delete\" " .
					"data-version-id=\"{$data['version_id']}\" data-version-name=\"{$data['version_name']}\" " .
					"class=\"icon i_close clickable\">&nbsp;</span>";
			} else {
				$row['actions'] = '';
			}

			$output['aaData'][] = $row;
		}

		sendJsonResponse(200, $output);
	} catch (DatabaseException $e) {
		sendJsonResponse(500, array('message' => tr('An unexpected error occurred: %s', $e->getMessage())));
	}

	sendJsonResponse(400, tr('Bad request.'));
}

/**
 * Normalize the given path
 *
 * @param string $path Path
 * @return string
 */
function _normalizePath($path)
{
	if (!strlen($path)) {
		return ".";
	}

	$isAbsolute = $path[0];
	$trailingSlash = $path[strlen($path) - 1];
	$up = 0;
	$peices = array_values(array_filter(explode("/", $path), function ($n) {
		return !!$n;
	}));

	for ($i = count($peices) - 1; $i >= 0; $i--) {
		$last = $peices[$i];

		if ($last == ".") {
			array_splice($peices, $i, 1);
		} else if ($last == "..") {
			array_splice($peices, $i, 1);
			$up++;
		} else if ($up) {
			array_splice($peices, $i, 1);
			$up--;
		}
	}

	$path = implode("/", $peices);
	if (!$path && !$isAbsolute) {
		$path = ".";
	}

	if ($path && $trailingSlash == "/") {
		$path .= "/";
	}

	return ($isAbsolute == "/" ? "/" : "") . $path;
}

/***********************************************************************************************************************
 * Main
 */

EventManager::getInstance()->dispatch(Events::onAdminScriptStart);
check_login('admin');

if (isset($_REQUEST['action'])) {
	if (is_xhr()) {
		switch (clean_input($_REQUEST['action'])) {
			case 'table':
				getTable();
				break;
			case 'add':
				add();
				break;
			case 'get':
				get();
				break;
			case 'edit':
				edit();
				break;
			case 'delete':
				delete();
				break;
			case 'phpinfo';
				generatePhpInfo();
				break;
			default:
				sendJsonResponse(400, array('message' => tr('Bad request.')));
		}
	}

	showBadRequestErrorPage();
}

/** @var PluginManager $pluginManager */
$pluginManager = Registry::get('pluginManager');
$phpSwitcher = $pluginManager->pluginGet('PhpSwitcher');

$tpl = new TemplateEngine();
$tpl->define_dynamic(array(
	'layout' => 'shared/layouts/ui.tpl',
	'page' => '../../plugins/PhpSwitcher/themes/default/view/admin/page.tpl',
	'phpinfo_button' => 'page',
	'phpinfo_js' => 'page',
	'page_message' => 'layout',
));

EventManager::getInstance()->registerListener('onGetJsTranslations', function ($e) use ($phpSwitcher) {
	/** @var $e \iMSCP_Events_Event */
	$translations = $e->getParam('translations');
	$translations->PhpSwitcher = array(
		'dataTable' => getDataTablesPluginTranslations(false),
		'save' => tr('Save'),
		'cancel' => tr('Cancel'),
		'add' => tr('New PHP version'),
		'edit' => tr('Edit %%s version'),
		'delete_confirm' => tr('Are you sure you want to delete this PHP version?'),
		'unknown_action' => tr('Unknown Action'),
		'request_timeout' => tr('Request Timeout: The server took too long to send the data.'),
		'request_error' => tr('An unexpected error occurred.')
	);

	if ($phpSwitcher->getConfigParam('phpinfo', false)) {
		$translations->PhpSwitcher['phpinfo_confirm'] = tr('Are you sure you want to re-generate all PHP info files?');
	}
});

$tpl->assign(array(
	'TR_PAGE_TITLE' => tr('Admin / Settings / PHP Switcher'),
	'DATATABLE_TRANSLATIONS' => getDataTablesPluginTranslations(),
	'TR_NAME' => tr('PHP version'),
	'TR_BINARY' => tr('PHP binary'),
	'TR_STATUS' => tr('Status'),
	'TR_ACTIONS' => tr('Actions'),
	'TR_BINARY_PATH' => tr('PHP binary path'),
	'TR_PROCESSING_DATA' => tr('Processing...'),
	'TR_ADD_NEW_VERSION' => tr('Add new PHP version'),
	'TR_ADD_NEW_VERSION_TOOLTIP' => tohtml(tr('Add new PHP version'), 'htmlAttr')
));

if (!$phpSwitcher->getConfigParam('phpinfo', false)) {
	$tpl->assign(array(
		'PHPINFO_BUTTON' => '',
		'PHPINFO_JS' => ''
	));
} else {
	$tpl->assign(array(
		'TR_REGENERATE_PHPINFO' => tohtml(tr('Re-generate phpinfo'), 'htmlAttr'),
		'TR_REGENERATE_PHPINFO_TOOLTIP' => tohtml(tr('Re-generate PHP info files.'), 'htmlAttr'),
	));
}

generateNavigation($tpl);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');
EventManager::getInstance()->dispatch(Events::onAdminScriptEnd, array('templateEngine' => $tpl));
$tpl->prnt();
