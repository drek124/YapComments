<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Administrator\Controller;

\defined('_JEXEC') or die;

use Drek124\Component\YapComments\Administrator\Helper\YapHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Utilities\ArrayHelper;

/**
 * Comments list controller: state changes, pinning and deletion.
 */
class CommentsController extends AdminController
{
    protected $text_prefix = 'COM_YAPCOMMENTS_COMMENTS';

    public function __construct($config = [], $factory = null, $app = null, $input = null)
    {
        parent::__construct($config, $factory, $app, $input);

        $this->registerTask('notspam', 'spam');
        $this->registerTask('unpin', 'pin');
    }

    public function getModel($name = 'Comment', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    public function publish()
    {
        parent::publish();
        $this->redirectBack();
    }

    /**
     * Honour an internal "return" URL so quick actions on the dashboard stay there.
     */
    private function redirectBack(): void
    {
        $return = base64_decode($this->input->getBase64('return', ''), true);

        if ($return && Uri::isInternal($return)) {
            $this->setRedirect($return);
        }
    }

    /**
     * Mark comments as spam (or restore them with "notspam").
     */
    public function spam(): void
    {
        $this->checkToken();

        $ids   = ArrayHelper::toInteger((array) $this->input->get('cid', [], 'array'));
        $value = $this->getTask() === 'spam' ? YapHelper::STATE_SPAM : YapHelper::STATE_PUBLISHED;

        if ($ids && $this->app->getIdentity()->authorise('core.edit.state', 'com_yapcomments')) {
            $model = $this->getModel();

            if ($model->publish($ids, $value)) {
                $this->setMessage(Text::plural($value === YapHelper::STATE_SPAM ? 'COM_YAPCOMMENTS_N_ITEMS_SPAMMED' : 'COM_YAPCOMMENTS_N_ITEMS_NOT_SPAM', \count($ids)));
            } else {
                $this->setMessage($model->getError(), 'error');
            }
        }

        $this->setRedirect(Route::_('index.php?option=com_yapcomments&view=comments', false));
        $this->redirectBack();
    }

    /**
     * Pin or unpin comments to the top of their thread.
     */
    public function pin(): void
    {
        $this->checkToken();

        $ids   = ArrayHelper::toInteger((array) $this->input->get('cid', [], 'array'));
        $value = $this->getTask() === 'pin' ? 1 : 0;

        if ($ids && $this->app->getIdentity()->authorise('core.edit.state', 'com_yapcomments')) {
            $this->getModel()->pin($ids, $value);
            $this->setMessage(Text::plural($value ? 'COM_YAPCOMMENTS_N_ITEMS_PINNED' : 'COM_YAPCOMMENTS_N_ITEMS_UNPINNED', \count($ids)));
        }

        $this->setRedirect(Route::_('index.php?option=com_yapcomments&view=comments', false));
        $this->redirectBack();
    }
}
