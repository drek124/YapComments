<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\Database\DatabaseInterface;

/**
 * Package installation script: enables the content plugin on first install.
 */
class Pkg_YapcommentsInstallerScript extends InstallerScript
{
    protected $minimumPhp = '8.1.0';

    protected $minimumJoomla = '5.0.0';

    public function postflight($type, $parent)
    {
        if ($type !== 'install' && $type !== 'discover_install') {
            return true;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('content'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('yapcomments'));

        $db->setQuery($query)->execute();

        return true;
    }
}
