<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Remembers the title, URL and access level of non-article items that a
 * thread was rendered for, so the API can resolve them later even when the
 * owning extension does not answer "onYapCommentsResolve".
 *
 * Rows are only ever written from the server-side page render, never from
 * request input, so a client cannot invent items or URLs.
 */
final class ItemRegistry
{
    private const COLUMNS = ['title', 'url', 'language', 'access', 'author_id', 'created', 'mode'];

    /**
     * Store or refresh an item. Writes only when something changed.
     *
     * @param  array{title:string, url:string, language:string, access:int, author_id:int, created:string, mode:string}  $data
     */
    public static function remember(string $context, int $id, array $data): void
    {
        if ($id < 1 || $data['url'] === '') {
            return;
        }

        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $row = self::row($context, $id);

        $data['title']   = mb_substr($data['title'], 0, 255);
        $data['url']     = mb_substr($data['url'], 0, 1024);
        $data['created'] = $data['created'] !== '' ? Factory::getDate($data['created'])->toSql() : null;

        if ($row) {
            $changed = false;

            foreach (self::COLUMNS as $col) {
                if ((string) ($row->$col ?? '') !== (string) ($data[$col] ?? '')) {
                    $changed = true;
                    break;
                }
            }

            if (!$changed) {
                return;
            }

            $obj = (object) (['context' => $context, 'item_id' => $id] + $data);
            $db->updateObject('#__yapcomments_items', $obj, ['context', 'item_id'], true);

            return;
        }

        $obj = (object) (['context' => $context, 'item_id' => $id] + $data);

        try {
            $db->insertObject('#__yapcomments_items', $obj);
        } catch (\RuntimeException) {
            // Another request inserted it first; nothing to do.
        }
    }

    /**
     * A remembered item in ContextResolver's shape, or null when unknown or
     * not viewable by the user.
     */
    public static function find(string $context, int $id, ?User $user = null): ?object
    {
        $row = self::row($context, $id);

        if (!$row) {
            return null;
        }

        $user ??= Factory::getApplication()->getIdentity() ?? new User();

        if ((int) $row->access > 0 && !\in_array((int) $row->access, $user->getAuthorisedViewLevels(), true)) {
            return null;
        }

        return (object) [
            'context'   => $context,
            'id'        => $id,
            'title'     => (string) $row->title,
            'url'       => (string) $row->url,
            'language'  => (string) ($row->language ?: '*'),
            'author_id' => (int) $row->author_id,
            'created'   => (string) ($row->created ?? ''),
            'mode'      => \in_array($row->mode, [ContextResolver::MODE_LOCKED, ContextResolver::MODE_HIDDEN], true) ? $row->mode : ContextResolver::MODE_OPEN,
        ];
    }

    public static function forget(string $context, int $id): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $db->setQuery(
            $db->createQuery()
                ->delete($db->quoteName('#__yapcomments_items'))
                ->where($db->quoteName('context') . ' = :context')
                ->where($db->quoteName('item_id') . ' = :id')
                ->bind(':context', $context)
                ->bind(':id', $id, ParameterType::INTEGER)
        )->execute();
    }

    private static function row(string $context, int $id): ?object
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        try {
            return $db->setQuery(
                $db->createQuery()
                    ->select($db->quoteName(self::COLUMNS))
                    ->from($db->quoteName('#__yapcomments_items'))
                    ->where($db->quoteName('context') . ' = :context')
                    ->where($db->quoteName('item_id') . ' = :id')
                    ->bind(':context', $context)
                    ->bind(':id', $id, ParameterType::INTEGER)
            )->loadObject() ?: null;
        } catch (\RuntimeException) {
            // Table missing (schema not updated yet).
            return null;
        }
    }
}
