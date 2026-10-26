<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Site\Helper;

\defined('_JEXEC') or die;

use Drek124\Component\YapComments\Administrator\Helper\YapHelper;
use Joomla\CMS\Event\AbstractEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\User\User;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Resolves the content item a thread is attached to and decides whether
 * comments are open, read-only (locked) or hidden for it.
 *
 * com_content articles are supported out of the box. Other extensions can
 * attach threads to their own items by listening to "onYapCommentsResolve"
 * and setting a "result" argument (an object with title, url, mode, ...).
 */
final class ContextResolver
{
    public const MODE_OPEN   = 'open';
    public const MODE_LOCKED = 'locked';
    public const MODE_HIDDEN = 'hidden';

    /** @var array<string, ?object> */
    private static array $cache = [];

    /**
     * @return object|null  {context, id, title, url, language, author_id, created, mode}
     */
    public static function resolve(string $context, int $id, ?User $user = null): ?object
    {
        $key = $context . ':' . $id;

        if (!\array_key_exists($key, self::$cache)) {
            self::$cache[$key] = $context === 'com_content.article'
                ? self::article($id, $user)
                : self::external($context, $id);

            if (self::$cache[$key] !== null) {
                self::applyAutoClose(self::$cache[$key]);
            }
        }

        return self::$cache[$key];
    }

    private static function article(int $id, ?User $user): ?object
    {
        $app  = Factory::getApplication();
        $user ??= $app->getIdentity() ?? new User();
        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $now  = Factory::getDate()->toSql();

        $query = $db->createQuery()
            ->select($db->quoteName(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.created', 'a.created_by', 'a.attribs']))
            ->from($db->quoteName('#__content', 'a'))
            ->join('INNER', $db->quoteName('#__categories', 'c'), 'c.id = a.catid')
            ->where($db->quoteName('a.id') . ' = :id')
            ->where($db->quoteName('a.state') . ' = 1')
            ->where($db->quoteName('c.published') . ' = 1')
            ->whereIn($db->quoteName('a.access'), $user->getAuthorisedViewLevels())
            ->whereIn($db->quoteName('c.access'), $user->getAuthorisedViewLevels())
            ->where('(' . $db->quoteName('a.publish_up') . ' IS NULL OR ' . $db->quoteName('a.publish_up') . ' <= :now1)')
            ->where('(' . $db->quoteName('a.publish_down') . ' IS NULL OR ' . $db->quoteName('a.publish_down') . ' > :now2)')
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':now1', $now)
            ->bind(':now2', $now);

        $row = $db->setQuery($query)->loadObject();

        if (!$row) {
            return null;
        }

        $attribs = new Registry($row->attribs);
        $mode    = (string) $attribs->get('yapcomments_mode', '');

        if (!\in_array($mode, [self::MODE_OPEN, self::MODE_LOCKED, self::MODE_HIDDEN], true)) {
            $mode = self::categoryAllowed((int) $row->catid) ? self::MODE_OPEN : self::MODE_HIDDEN;
        }

        $url = Route::link(
            'site',
            RouteHelper::getArticleRoute($row->id . ':' . $row->alias, (int) $row->catid, $row->language),
            false,
            Route::TLS_IGNORE,
            true
        );

        return (object) [
            'context'   => 'com_content.article',
            'id'        => (int) $row->id,
            'title'     => (string) $row->title,
            'url'       => $url,
            'language'  => (string) $row->language,
            'author_id' => (int) $row->created_by,
            'created'   => (string) $row->created,
            'mode'      => $mode,
        ];
    }

    /**
     * Category include/exclude rules come from the content plugin's options.
     */
    private static function categoryAllowed(int $catid): bool
    {
        $plugin = PluginHelper::getPlugin('content', 'yapcomments');

        if (!$plugin) {
            return true;
        }

        $params     = new Registry($plugin->params);
        $mode       = $params->get('category_mode', 'all');
        $categories = array_map('intval', (array) $params->get('categories', []));

        return match ($mode) {
            'include' => \in_array($catid, $categories, true),
            'exclude' => !\in_array($catid, $categories, true),
            default   => true,
        };
    }

    private static function external(string $context, int $id): ?object
    {
        PluginHelper::importPlugin('content');
        PluginHelper::importPlugin('yapcomments');

        $event = AbstractEvent::create('onYapCommentsResolve', [
            'subject' => new \stdClass(),
            'context' => $context,
            'id'      => $id,
        ]);

        Factory::getApplication()->getDispatcher()->dispatch('onYapCommentsResolve', $event);

        $result = $event->getArgument('result');

        if (!\is_object($result) || empty($result->url)) {
            return null;
        }

        return (object) [
            'context'   => $context,
            'id'        => $id,
            'title'     => (string) ($result->title ?? ''),
            'url'       => (string) $result->url,
            'language'  => (string) ($result->language ?? '*'),
            'author_id' => (int) ($result->author_id ?? 0),
            'created'   => (string) ($result->created ?? ''),
            'mode'      => \in_array($result->mode ?? '', [self::MODE_LOCKED, self::MODE_HIDDEN], true) ? $result->mode : self::MODE_OPEN,
        ];
    }

    private static function applyAutoClose(object $item): void
    {
        $days = (int) YapHelper::params()->get('auto_close_days', 0);

        if ($item->mode === self::MODE_OPEN && $days > 0 && $item->created !== ''
            && Factory::getDate($item->created)->toUnix() < time() - $days * 86400) {
            $item->mode = self::MODE_LOCKED;
        }
    }
}
