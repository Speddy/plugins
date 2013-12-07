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

return array(
	// Jailkit installation directory.
	// This path is used as value of the --prefix option (JailKit configure script).
	// IMPORTANT: You must never change this parameter while updating the plugin to a new version.
	'install_path' => '/usr/local', # (Recommended value)

	// Full path to the root jail directory which holds all jails. Be sure that the partition in which this directory is
	// living has enough space to host the jails.
	// IMPORTANT: You must never change this parameter while updating the plugin to a new version.
	'root_jail_dir' => '/home/imscp-jails',

	// See man shells
	// Don't change this value if you do not know what you are doing
	'shell' => '/bin/bash', # (Recommended value)

	// See man jk_init
	'jail_app_sections' => array(
		'imscp-base', // Include Pre-selected sections, users and groups
		'mysql-client'
	),

	// See man jk_cp
	'jail_additional_apps' => array(
		'/bin/hostname',
		'/usr/bin/basename',
		'/usr/bin/dircolors',
		'/usr/bin/dirname',
		'/usr/bin/clear_console',
		'/usr/bin/env',
		'/usr/bin/id',
		'/usr/bin/groups',
		'/usr/bin/lesspipe',
		'/usr/bin/tput',
		'/usr/bin/which'
	),

	// See man jk_socketd
	'jail_socketd_base' => '512',
	'jail_socketd_peak' => '2048',
	'jail_socketd_interval' => '5.0'
);
