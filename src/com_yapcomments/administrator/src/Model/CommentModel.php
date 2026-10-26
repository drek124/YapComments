<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\Database\ParameterType;
use Joomla\Utilities\ArrayHelper;

/**
 * Single comment model for the administrator.
 */
class CommentModel extends AdminModel
{
    public $typeAlias = 'com_yapcomments.comment';

    protected $text_prefix = 'COM_YAPCOMMENTS_COMMENT';

    public function getTable($name = 'Comment', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_yapcomments.comment', 'comment', ['control' => 'jform', 'load_data' => $loadData]);

        return $form ?: false;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_yapcomments.edit.comment.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        $this->preprocessData('com_yapcomments.comment', $data);

        return $data;
    }

    protected function canDelete($record)
    {
        return $this->getCurrentUser()->authorise('core.delete', 'com_yapcomments');
    }

    protected function canEditState($record)
    {
        return $this->getCurrentUser()->authorise('core.edit.state', 'com_yapcomments');
    }

    protected function prepareTable($table)
    {
        $table->modified    = Factory::getDate()->toSql();
        $table->modified_by = (int) $this->getCurrentUser()->id;
    }

    /**
     * Pin or unpin comments.
     */
    public function pin(array $pks, int $value): bool
    {
        $pks = ArrayHelper::toInteger($pks);

        if (!$pks) {
            return false;
        }

        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->update($db->quoteName('#__yapcomments_comments'))
            ->set($db->quoteName('pinned') . ' = :pinned')
            ->whereIn($db->quoteName('id'), $pks)
            ->bind(':pinned', $value, ParameterType::INTEGER);

        $db->setQuery($query)->execute();

        return true;
    }
}
