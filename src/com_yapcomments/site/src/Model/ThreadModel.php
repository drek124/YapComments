<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Site\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

/**
 * Reads comment threads.
 */
class ThreadModel extends BaseDatabaseModel
{
    /**
     * One page of top-level comments with all of their replies nested in "children".
     *
     * @return array{items: object[], total: int}
     */
    public function getPage(string $context, int $itemId, string $sort, int $page, int $limit, int $userId, bool $moderator): array
    {
        $db = $this->getDatabase();

        $roots = $this->baseQuery($context, $itemId, $userId, $moderator)
            ->where($db->quoteName('a.parent_id') . ' = 0');

        $count = clone $roots;
        $count->clear('select')->clear('order')->select('COUNT(*)');
        $total = (int) $db->setQuery($count)->loadResult();

        $roots->order($db->quoteName('a.pinned') . ' DESC');

        match ($sort) {
            'oldest' => $roots->order($db->quoteName('a.created') . ' ASC'),
            'top'    => $roots->order('(CAST(' . $db->quoteName('a.likes') . ' AS SIGNED) - CAST(' . $db->quoteName('a.dislikes') . ' AS SIGNED)) DESC')
                ->order($db->quoteName('a.created') . ' DESC'),
            default  => $roots->order($db->quoteName('a.created') . ' DESC'),
        };

        $roots->order($db->quoteName('a.id') . ' DESC');

        $items = $db->setQuery($roots, max(0, ($page - 1) * $limit), $limit)->loadObjectList('id');

        if (!$items) {
            return ['items' => [], 'total' => $total];
        }

        $replies = $this->baseQuery($context, $itemId, $userId, $moderator)
            ->whereIn($db->quoteName('a.root_id'), array_keys($items))
            ->order($db->quoteName('a.created') . ' ASC')
            ->order($db->quoteName('a.id') . ' ASC');

        $all = $items;

        foreach ($items as $item) {
            $item->children = [];
        }

        foreach ($db->setQuery($replies)->loadObjectList() as $reply) {
            $reply->children = [];

            // Replies whose parent is not visible (pending/spam) are dropped with it.
            if (isset($all[$reply->parent_id])) {
                $all[$reply->parent_id]->children[] = $reply;
                $all[$reply->id] = $reply;
            }
        }

        return ['items' => array_values($items), 'total' => $total];
    }

    /**
     * A single visible comment.
     */
    public function getComment(int $id, int $userId = 0, bool $moderator = false): ?object
    {
        $db    = $this->getDatabase();
        $query = $this->select()
            ->where($db->quoteName('a.id') . ' = :cid')
            ->bind(':cid', $id, ParameterType::INTEGER);

        $row = $db->setQuery($query)->loadObject();

        if (!$row || !$this->visible($row, $userId, $moderator)) {
            return null;
        }

        $row->children = [];

        return $row;
    }

    /**
     * Number of published comments of an item (all depths).
     */
    public function countPublished(string $context, int $itemId): int
    {
        return $this->countMany($context, [$itemId])[$itemId] ?? 0;
    }

    /**
     * Published comment counts for many items at once.
     *
     * @return array<int, int>
     */
    public function countMany(string $context, array $itemIds): array
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds)));

        if (!$itemIds) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select([$db->quoteName('item_id'), 'COUNT(*) AS ' . $db->quoteName('total')])
            ->from($db->quoteName('#__yapcomments_comments'))
            ->where($db->quoteName('context') . ' = :context')
            ->where($db->quoteName('state') . ' = 1')
            ->whereIn($db->quoteName('item_id'), $itemIds)
            ->group($db->quoteName('item_id'))
            ->bind(':context', $context);

        return array_map('intval', $db->setQuery($query)->loadAssocList('item_id', 'total'));
    }

    /**
     * The current voter's votes for the given comments.
     *
     * @return array<int, int>  comment id => 1 | -1
     */
    public function getVotes(array $ids, string $voter): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select($db->quoteName(['comment_id', 'vote']))
            ->from($db->quoteName('#__yapcomments_votes'))
            ->where($db->quoteName('voter') . ' = :voter')
            ->whereIn($db->quoteName('comment_id'), $ids)
            ->bind(':voter', $voter);

        return array_map('intval', $db->setQuery($query)->loadAssocList('comment_id', 'vote'));
    }

    /**
     * Which of the given users belong to the Administrator or Super Users group
     * (or to any group nested inside them).
     *
     * @return array<int, true>  user id => true
     */
    public function getAdministrators(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        if (!$userIds) {
            return [];
        }

        $db     = $this->getDatabase();
        $groups = [7, 8];

        // Nested set: the listed groups and every group inside them.
        $query = $db->createQuery()
            ->select('DISTINCT ' . $db->quoteName('m.user_id'))
            ->from($db->quoteName('#__user_usergroup_map', 'm'))
            ->join('INNER', $db->quoteName('#__usergroups', 'g'), 'g.id = m.group_id')
            ->join('INNER', $db->quoteName('#__usergroups', 'top'), 'g.lft >= top.lft AND g.rgt <= top.rgt')
            ->join('INNER', $db->quoteName('#__users', 'u'), 'u.id = m.user_id AND u.block = 0')
            ->whereIn($db->quoteName('top.id'), $groups)
            ->whereIn($db->quoteName('m.user_id'), $userIds);

        return array_fill_keys(array_map('intval', $db->setQuery($query)->loadColumn()), true);
    }

    public function visible(object $row, int $userId, bool $moderator): bool
    {
        $state = (int) $row->state;

        return $state === 1 || ($state === 0 && ($moderator || ($userId > 0 && (int) $row->user_id === $userId)));
    }

    private function select(): QueryInterface
    {
        $db = $this->getDatabase();

        return $db->createQuery()
            ->select('a.*')
            ->select($db->quoteName(['u.name', 'u.email'], ['user_name', 'user_email']))
            ->from($db->quoteName('#__yapcomments_comments', 'a'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = a.user_id');
    }

    private function baseQuery(string $context, int $itemId, int $userId, bool $moderator): QueryInterface
    {
        $db    = $this->getDatabase();
        $query = $this->select()
            ->where($db->quoteName('a.context') . ' = :context')
            ->where($db->quoteName('a.item_id') . ' = :item')
            ->bind(':context', $context)
            ->bind(':item', $itemId, ParameterType::INTEGER);

        if ($moderator) {
            $query->where($db->quoteName('a.state') . ' IN (0, 1)');
        } elseif ($userId > 0) {
            $query->where('(' . $db->quoteName('a.state') . ' = 1 OR (' . $db->quoteName('a.state') . ' = 0 AND ' . $db->quoteName('a.user_id') . ' = :uid))')
                ->bind(':uid', $userId, ParameterType::INTEGER);
        } else {
            $query->where($db->quoteName('a.state') . ' = 1');
        }

        return $query;
    }
}
