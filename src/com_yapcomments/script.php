<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Rules;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Table\Asset;
use Joomla\Database\DatabaseInterface;

/**
 * Installation script for com_yapcomments.
 */
class Com_YapcommentsInstallerScript extends InstallerScript
{
    protected $minimumPhp = '8.1.0';

    protected $minimumJoomla = '5.0.0';

    /**
     * Sensible default permissions for a fresh install.
     * Group IDs are Joomla's stock groups: 1 Public, 2 Registered, 3 Author,
     * 4 Editor, 6 Manager, 9 Guest.
     */
    private const DEFAULT_RULES = [
        'yapcomments.view'          => [1 => 1],
        'yapcomments.post'          => [2 => 1, 9 => 1],
        'yapcomments.reply'         => [2 => 1, 9 => 1],
        'yapcomments.vote'          => [2 => 1],
        'yapcomments.markdown'      => [2 => 1],
        'yapcomments.code'          => [2 => 1],
        'yapcomments.links'         => [3 => 1, 6 => 1],
        'yapcomments.images'        => [3 => 1, 6 => 1],
        'yapcomments.edit.own'      => [2 => 1],
        'yapcomments.delete.own'    => [2 => 1],
        'yapcomments.autoapprove'   => [2 => 1],
        'yapcomments.moderate'      => [4 => 1, 6 => 1],
        'yapcomments.bypass.limits' => [4 => 1, 6 => 1],
    ];

    public function postflight($type, $parent)
    {
        if ($type !== 'install') {
            return true;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $asset = new Asset($db);

        if (!$asset->loadByName('com_yapcomments')) {
            $asset->setLocation(1, 'last-child');
            $asset->name  = 'com_yapcomments';
            $asset->title = 'com_yapcomments';
            $asset->rules = '{}';
        }

        $rules = new Rules($asset->rules);
        $rules->merge(new Rules(self::DEFAULT_RULES));
        $asset->rules = (string) $rules;
        $asset->store();

        return true;
    }
}
