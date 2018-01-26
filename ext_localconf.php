<?php

/* Copyright (C) 2015 Oliver Eglseder <php@vxvr.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

$GLOBALS['TYPO3_CONF_VARS']['SYS']['FileInfo']['fileExtensionToMimeType']['png'] = 'image/png';

// register sftp driver for file abstraction layer
$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers']['Sftp'] = [
    'class' => 'VerteXVaaR\\FalSftp\\Driver\\SftpDriver',
    'flexFormDS' => 'FILE:EXT:falsftp/Configuration/FlexForm/DriverConfiguration.xml',
    'label' => 'SFTP Driver',
    'shortName' => 'Sftp',
];
