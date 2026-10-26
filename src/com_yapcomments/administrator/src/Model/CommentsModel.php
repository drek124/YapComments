<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

/**
 * Comments list model for the administrator.
 */
class CommentsModel extends ListModel
{
    public function __construct($config = [], $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'state', 'a.state',
                'created', 'a.created',
                'name', 'a.name',
                'item_title', 'a.item_title',
                'likes', 'a.likes',
                'dislikes', 'a.dislikes',
                'pinned', 'a.pinned',
                'context', 'a.context',
                'item_id', 'a.item_id',
            ];
        }

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'a.created', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.state');
        $id .= ':' . $this->getState('filter.context');
        $id .= ':' . $this->getState('filter.pinned');
        $id .= ':' . $this->getState('filter.item_id');

        return parent::getStoreId($id);
    }

    protected function getListQuery(): QueryInterface
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select('a.*')
            ->select($db->quoteName(['u.name', 'u.username'], ['user_name', 'user_username']))
            ->select($db->quoteName('uc.name', 'editor'))
            ->from($db->quoteName('#__yapcomments_comments', 'a'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = a.user_id')
            ->join('LEFT', $db->quoteName('#__users', 'uc'), 'uc.id = a.checked_out');

        $state = (string) $this->getState('filter.state');

        if (is_numeric($state)) {
            $state = (int) $state;
            $query->where($db->quoteName('a.state') . ' = :state')->bind(':state', $state, ParameterType::INTEGER);
        } elseif ($state !== '*') {
            $query->where($db->quoteName('a.state') . ' IN (0, 1)');
        }

        if ($context = (string) $this->getState('filter.context')) {
            $query->where($db->quoteName('a.context') . ' = :context')->bind(':context', $context);
        }

        if (is_numeric($pinned = $this->getState('filter.pinned'))) {
            $pinned = (int) $pinned;
            $query->where($db->quoteName('a.pinned') . ' = :pinned')->bind(':pinned', $pinned, ParameterType::INTEGER);
        }

        if ($itemId = (int) $this->getState('filter.item_id')) {
            $query->where($db->quoteName('a.item_id') . ' = :itemid')->bind(':itemid', $itemId, ParameterType::INTEGER);
        }

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            if (stripos($search, 'id:') === 0) {
                $id = (int) substr($search, 3);
                $query->where($db->quoteName('a.id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
            } elseif (stripos($search, 'ip:') === 0) {
                $ip = trim(substr($search, 3)) . '%';
                $query->where($db->quoteName('a.ip') . ' LIKE :ip')->bind(':ip', $ip);
            } else {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
                $query->where('(' . implode(' OR ', [
                    $db->quoteName('a.body') . ' LIKE :s1',
                    $db->quoteName('a.name') . ' LIKE :s2',
                    $db->quoteName('a.email') . ' LIKE :s3',
                    $db->quoteName('a.item_title') . ' LIKE :s4',
                    $db->quoteName('u.name') . ' LIKE :s5',
                ]) . ')')
                    ->bind([':s1', ':s2', ':s3', ':s4', ':s5'], $like);
            }
        }

        $ordering  = $this->getState('list.ordering', 'a.created');
        $direction = strtoupper($this->getState('list.direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $query->order($db->escape($ordering) . ' ' . $direction);

        return $query;
    }

    /**
     * Distinct contexts for the filter.
     *
     * @return string[]
     */
    public function getContexts(): array
    {
        $db = $this->getDatabase();

        return $db->setQuery(
            $db->createQuery()
                ->select('DISTINCT ' . $db->quoteName('context'))
                ->from($db->quoteName('#__yapcomments_comments'))
                ->order($db->quoteName('context'))
        )->loadColumn();
    }
}
