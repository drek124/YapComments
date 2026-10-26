<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Administrator\View\Comment;

\defined('_JEXEC') or die;

use Drek124\Component\YapComments\Administrator\Helper\MarkdownRenderer;
use Drek124\Component\YapComments\Administrator\Helper\YapHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Comment edit view.
 */
class HtmlView extends BaseHtmlView
{
    protected $form;

    protected $item;

    /** Rendered preview of the stored comment. */
    protected string $preview = '';

    public function display($tpl = null)
    {
        $model      = $this->getModel();
        $this->form = $model->getForm();
        $this->item = $model->getItem();

        if (\count($errors = $model->getErrors())) {
            throw new GenericDataException(implode("\n", $errors), 500);
        }

        if (!empty($this->item->id)) {
            $this->preview = (new MarkdownRenderer(YapHelper::params()))->render((string) $this->item->body, (int) $this->item->flags);
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_YAPCOMMENTS_MANAGER_COMMENT_EDIT'), 'comment');

        if ($this->getCurrentUser()->authorise('core.edit', 'com_yapcomments')) {
            $toolbar->apply('comment.apply');
            $toolbar->save('comment.save');
        }

        $toolbar->cancel('comment.cancel', 'JTOOLBAR_CLOSE');
    }
}
