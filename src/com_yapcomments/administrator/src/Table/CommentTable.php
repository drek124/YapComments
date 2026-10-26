<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;

/**
 * Comment table.
 */
class CommentTable extends Table
{
    protected $_supportNullValue = true;

    public function __construct(DatabaseInterface $db, ?DispatcherInterface $dispatcher = null)
    {
        parent::__construct('#__yapcomments_comments', 'id', $db, $dispatcher);

        $this->setColumnAlias('published', 'state');
    }

    public function check()
    {
        $this->body = trim((string) $this->body);

        if ($this->body === '' && !((int) $this->flags & 16)) {
            $this->setError(Text::_('COM_YAPCOMMENTS_ERROR_EMPTY'));

            return false;
        }

        $this->name    = mb_substr(trim(strip_tags((string) $this->name)), 0, 100);
        $this->email   = mb_substr(trim((string) $this->email), 0, 150);
        $this->context = $this->context ?: 'com_content.article';

        if (empty($this->created)) {
            $this->created = Factory::getDate()->toSql();
        }

        return parent::check();
    }

    /**
     * Delete a comment together with all of its replies and their votes.
     */
    public function delete($pk = null)
    {
        $pk = (int) ($pk ?? $this->id);
        $db = $this->getDatabase();

        $ids   = [$pk];
        $queue = [$pk];

        while ($queue) {
            $query = $db->createQuery()
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__yapcomments_comments'))
                ->whereIn($db->quoteName('parent_id'), $queue);
            $queue = array_map('intval', $db->setQuery($query)->loadColumn());
            $ids   = array_merge($ids, $queue);
        }

        $children = array_diff($ids, [$pk]);

        if ($children) {
            $db->setQuery(
                $db->createQuery()
                    ->delete($db->quoteName('#__yapcomments_comments'))
                    ->whereIn($db->quoteName('id'), $children)
            )->execute();
        }

        $db->setQuery(
            $db->createQuery()
                ->delete($db->quoteName('#__yapcomments_votes'))
                ->whereIn($db->quoteName('comment_id'), $ids, ParameterType::INTEGER)
        )->execute();

        return parent::delete($pk);
    }
}
