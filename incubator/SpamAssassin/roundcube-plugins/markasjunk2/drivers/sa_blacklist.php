<?php

/**
 * SpamAssassin Blacklist driver
 * @version 2.0
 * @requires SAUserPrefs plugin
 * @author Philip Weir
 */

class markasjunk2_sa_blacklist
{
	private $sa_user;
	private $sa_table;
	private $sa_username_field;
	private $sa_preference_field;
	private $sa_value_field;

	public function spam($uids, $mbox)
	{
		$this->_do_list($uids, true);
	}

	public function ham($uids, $mbox)
	{
		$this->_do_list($uids, false);
	}

	private function _do_list($uids, $spam)
	{
		$rcmail = rcube::get_instance();
		$this->sa_user = $rcmail->config->get('sauserprefs_userid', "%u");
		$this->sa_table = $rcmail->config->get('sauserprefs_sql_table_name');
		$this->sa_username_field = $rcmail->config->get('sauserprefs_sql_username_field');
		$this->sa_preference_field = $rcmail->config->get('sauserprefs_sql_preference_field');
		$this->sa_value_field = $rcmail->config->get('sauserprefs_sql_value_field');

		$identity_arr = $rcmail->user->get_identity();
		$identity = $identity_arr['email'];
		$this->sa_user = str_replace('%u', $_SESSION['username'], $this->sa_user);
		$this->sa_user = str_replace('%l', $rcmail->user->get_username('local'), $this->sa_user);
		$this->sa_user = str_replace('%d', $rcmail->user->get_username('domain'), $this->sa_user);
		$this->sa_user = str_replace('%i', $identity, $this->sa_user);

		if (is_file($rcmail->config->get('markasjunk2_sauserprefs_config')) && !$rcmail->config->load_from_file($rcmail->config->get('markasjunk2_sauserprefs_config'))) {
			rcube::raise_error(array('code' => 527, 'type' => 'php',
				'file' => __FILE__, 'line' => __LINE__,
				'message' => "Failed to load config from " . $rcmail->config->get('markasjunk2_sauserprefs_config')), true, false);
			return false;
		}

		$db = rcube_db::factory($rcmail->config->get('sauserprefs_db_dsnw'), $rcmail->config->get('sauserprefs_db_dsnr'), $rcmail->config->get('sauserprefs_db_persistent'));
		$db->set_debug((bool)$rcmail->config->get('sql_debug'));
		$db->db_connect('w');

		// check DB connections and exit on failure
		if ($err_str = $db->is_error()) {
			rcube::raise_error(array(
				'code' => 603,
				'type' => 'db',
				'message' => $err_str), FALSE, TRUE);
		}

		foreach ($uids as $uid) {
			$message = new rcube_message($uid);
			$email = $message->sender['mailto'];

			if ($spam) {
				// delete any whitelisting for this address
				$db->query(
					"DELETE FROM `{$this->sa_table}` WHERE `{$this->sa_username_field}` = ? AND `{$this->sa_preference_field}` = ? AND `{$this->sa_value_field}` = ?;",
					$this->sa_user,
					'whitelist_from',
					$email);

				// check address is not already blacklisted
				$sql_result = $db->query(
								"SELECT `value` FROM `{$this->sa_table}` WHERE `{$this->sa_username_field}` = ? AND `{$this->sa_preference_field}` = ? AND `{$this->sa_value_field}` = ?;",
								$this->sa_user,
								'blacklist_from',
								$email);

				if (!$db->fetch_array($sql_result)) {
					$db->query(
						"INSERT INTO `{$this->sa_table}` (`{$this->sa_username_field}`, `{$this->sa_preference_field}`, `{$this->sa_value_field}`) VALUES (?, ?, ?);",
						$this->sa_user,
						'blacklist_from',
						$email);

					if ($rcmail->config->get('markasjunk2_debug'))
						rcube::write_log('markasjunk2', $this->sa_user . ' blacklist ' . $email);
				}
			}
			else {
				// delete any blacklisting for this address
				$db->query(
					"DELETE FROM `{$this->sa_table}` WHERE `{$this->sa_username_field}` = ? AND `{$this->sa_preference_field}` = ? AND `{$this->sa_value_field}` = ?;",
					$this->sa_user,
					'blacklist_from',
					$email);

				// check address is not already whitelisted
				$sql_result = $db->query(
								"SELECT `value` FROM `{$this->sa_table}` WHERE `{$this->sa_username_field}` = ? AND `{$this->sa_preference_field}` = ? AND `{$this->sa_value_field}` = ?;",
								$this->sa_user,
								'whitelist_from',
								$email);

				if (!$db->fetch_array($sql_result)) {
					$db->query(
						"INSERT INTO `{$this->sa_table}` (`{$this->sa_username_field}`, `{$this->sa_preference_field}`, `{$this->sa_value_field}`) VALUES (?, ?, ?);",
						$this->sa_user,
						'whitelist_from',
						$email);

					if ($rcmail->config->get('markasjunk2_debug'))
						rcube::write_log('markasjunk2', $this->sa_user . ' whitelist ' . $email);
				}
			}
		}
	}
}

?>