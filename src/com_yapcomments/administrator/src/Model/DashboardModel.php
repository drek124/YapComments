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
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\ParameterType;

/**
 * Statistics for the dashboard.
 */
class DashboardModel extends BaseDatabaseModel
{
    /**
     * Counters per state plus today's and vote totals.
     */
    public function getStats(): array
    {
        $db   = $this->getDatabase();
        $rows = $db->setQuery(
            $db->createQuery()
                ->select([$db->quoteName('state'), 'COUNT(*) AS ' . $db->quoteName('total')])
                ->from($db->quoteName('#__yapcomments_comments'))
                ->group($db->quoteName('state'))
        )->loadObjectList('state');

        $today = Factory::getDate('today')->toSql();

        $todayCount = (int) $db->setQuery(
            $db->createQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName('#__yapcomments_comments'))
                ->where($db->quoteName('created') . ' >= :today')
                ->bind(':today', $today)
        )->loadResult();

        $votes = $db->setQuery(
            $db->createQuery()
                ->select(['SUM(' . $db->quoteName('likes') . ') AS likes', 'SUM(' . $db->quoteName('dislikes') . ') AS dislikes'])
                ->from($db->quoteName('#__yapcomments_comments'))
        )->loadObject();

        $count = static fn (int $state): int => isset($rows[$state]) ? (int) $rows[$state]->total : 0;

        return [
            'published' => $count(1),
            'pending'   => $count(0),
            'spam'      => $count(-1),
            'trashed'   => $count(-2),
            'total'     => array_sum(array_map(static fn ($r) => (int) $r->total, $rows)),
            'today'     => $todayCount,
            'likes'     => (int) ($votes->likes ?? 0),
            'dislikes'  => (int) ($votes->dislikes ?? 0),
        ];
    }

    /**
     * Number of comments per day for the last $days days, oldest first.
     *
     * @return array<string, int>  Y-m-d => count
     */
    public function getActivity(int $days = 30): array
    {
        $db    = $this->getDatabase();
        $since = Factory::getDate('today -' . ($days - 1) . ' days')->toSql();

        $rows = $db->setQuery(
            $db->createQuery()
                ->select(['DATE(' . $db->quoteName('created') . ') AS ' . $db->quoteName('day'), 'COUNT(*) AS ' . $db->quoteName('total')])
                ->from($db->quoteName('#__yapcomments_comments'))
                ->where($db->quoteName('created') . ' >= :since')
                ->bind(':since', $since)
                ->group('DATE(' . $db->quoteName('created') . ')')
        )->loadAssocList('day', 'total');

        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day          = Factory::getDate('today -' . $i . ' days')->format('Y-m-d');
            $series[$day] = (int) ($rows[$day] ?? 0);
        }

        return $series;
    }

    /**
     * Items with the most published comments.
     */
    public function getTopItems(int $limit = 5): array
    {
        $db = $this->getDatabase();

        return $db->setQuery(
            $db->createQuery()
                ->select([
                    $db->quoteName('context'),
                    $db->quoteName('item_id'),
                    'MAX(' . $db->quoteName('item_title') . ') AS ' . $db->quoteName('item_title'),
                    'MAX(' . $db->quoteName('item_url') . ') AS ' . $db->quoteName('item_url'),
                    'COUNT(*) AS ' . $db->quoteName('total'),
                ])
                ->from($db->quoteName('#__yapcomments_comments'))
                ->where($db->quoteName('state') . ' = 1')
                ->group([$db->quoteName('context'), $db->quoteName('item_id')])
                ->order($db->quoteName('total') . ' DESC'),
            0,
            $limit
        )->loadObjectList();
    }

    /**
     * Most recent comments, optionally limited to one state.
     */
    public function getRecent(int $limit = 6, ?int $state = null): array
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select('a.*')
            ->select($db->quoteName('u.name', 'user_name'))
            ->from($db->quoteName('#__yapcomments_comments', 'a'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = a.user_id')
            ->order($db->quoteName('a.created') . ' DESC');

        if ($state !== null) {
            $query->where($db->quoteName('a.state') . ' = :state')->bind(':state', $state, ParameterType::INTEGER);
        }

        return $db->setQuery($query, 0, $limit)->loadObjectList();
    }
}
