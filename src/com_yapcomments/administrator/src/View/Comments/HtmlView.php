<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Administrator\View\Comments;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Comments list view.
 */
class HtmlView extends BaseHtmlView
{
    protected $items;

    protected $pagination;

    protected $state;

    public $filterForm;

    public $activeFilters;

    public function display($tpl = null)
    {
        $model               = $this->getModel();
        $this->items         = $model->getItems();
        $this->pagination    = $model->getPagination();
        $this->state         = $model->getState();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        if (\count($errors = $model->getErrors())) {
            throw new GenericDataException(implode("\n", $errors), 500);
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        $user    = $this->getCurrentUser();
        $toolbar = $this->getDocument()->getToolbar();
        $state   = (string) $this->state->get('filter.state');

        ToolbarHelper::title(Text::_('COM_YAPCOMMENTS_MANAGER_COMMENTS'), 'comments');

        if ($user->authorise('core.edit.state', 'com_yapcomments')) {
            $dropdown = $toolbar->dropdownButton('status-group', 'JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('icon-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();
            $childBar->publish('comments.publish', 'COM_YAPCOMMENTS_TOOLBAR_APPROVE')->listCheck(true);
            $childBar->unpublish('comments.unpublish', 'COM_YAPCOMMENTS_TOOLBAR_UNAPPROVE')->listCheck(true);
            $childBar->standardButton('pin', 'COM_YAPCOMMENTS_TOOLBAR_PIN', 'comments.pin')->icon('icon-pin')->listCheck(true);
            $childBar->standardButton('unpin', 'COM_YAPCOMMENTS_TOOLBAR_UNPIN', 'comments.unpin')->icon('icon-unpin')->listCheck(true);

            if ($state === '-1') {
                $childBar->standardButton('notspam', 'COM_YAPCOMMENTS_TOOLBAR_NOT_SPAM', 'comments.notspam')->icon('icon-check')->listCheck(true);
            } else {
                $childBar->standardButton('spam', 'COM_YAPCOMMENTS_TOOLBAR_SPAM', 'comments.spam')->icon('icon-warning')->listCheck(true);
            }

            if ($state !== '-2') {
                $childBar->trash('comments.trash')->listCheck(true);
            }
        }

        if (\in_array($state, ['-2', '-1'], true) && $user->authorise('core.delete', 'com_yapcomments')) {
            $toolbar->delete('comments.delete', 'JTOOLBAR_DELETE_FROM_TRASH')
                ->message('JGLOBAL_CONFIRM_DELETE')
                ->listCheck(true);
        }

        if ($user->authorise('core.admin', 'com_yapcomments') || $user->authorise('core.options', 'com_yapcomments')) {
            $toolbar->preferences('com_yapcomments');
        }
    }
}
