<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Administrator\View\Dashboard;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Dashboard view with moderation queue and statistics.
 */
class HtmlView extends BaseHtmlView
{
    protected array $stats = [];

    protected array $activity = [];

    protected array $topItems = [];

    protected array $pending = [];

    protected array $recent = [];

    public function display($tpl = null)
    {
        $model          = $this->getModel();
        $this->stats    = $model->getStats();
        $this->activity = $model->getActivity(30);
        $this->topItems = $model->getTopItems(6);
        $this->pending  = $model->getRecent(6, 0);
        $this->recent   = $model->getRecent(6, 1);

        $this->getDocument()->getWebAssetManager()
            ->getRegistry()->addExtensionRegistryFile('com_yapcomments');
        $this->getDocument()->getWebAssetManager()->useStyle('com_yapcomments.admin');

        ToolbarHelper::title(Text::_('COM_YAPCOMMENTS_DASHBOARD'), 'comments');

        $user = $this->getCurrentUser();

        if ($user->authorise('core.admin', 'com_yapcomments') || $user->authorise('core.options', 'com_yapcomments')) {
            $this->getDocument()->getToolbar()->preferences('com_yapcomments');
        }

        parent::display($tpl);
    }
}
