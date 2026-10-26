<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Site\Model;

\defined('_JEXEC') or die;

use Drek124\Component\YapComments\Administrator\Helper\MarkdownRenderer;
use Drek124\Component\YapComments\Administrator\Helper\YapHelper;
use Drek124\Component\YapComments\Administrator\Table\CommentTable;
use Drek124\Component\YapComments\Site\Helper\ContextResolver;
use Drek124\Component\YapComments\Site\Helper\Notifier;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * All write operations on comments and votes. Every method enforces the
 * relevant permission itself and throws \RuntimeException with an HTTP-like
 * code when something is not allowed.
 */
class PostModel extends BaseDatabaseModel
{
    private Registry $params;

    public function __construct($config = [], $factory = null)
    {
        parent::__construct($config, $factory);

        $this->params = YapHelper::params();
    }

    /**
     * Create a comment or reply.
     *
     * @param   array{parent_id?: int, body?: string, name?: string, email?: string}  $input
     *
     * @return  CommentTable
     */
    public function create(array $input, object $item, User $user): CommentTable
    {
        $this->assertOpen($item);

        $moderator = YapHelper::can('moderate', $user);
        $bypass    = $moderator || YapHelper::can('bypass.limits', $user);
        $parentId  = (int) ($input['parent_id'] ?? 0);
        $parent    = null;

        if (!YapHelper::can($parentId ? 'reply' : 'post', $user)) {
            $this->fail($parentId ? 'COM_YAPCOMMENTS_ERROR_NO_REPLY' : 'COM_YAPCOMMENTS_ERROR_NO_POST', 403);
        }

        $table = $this->getTable();
        $table->context = $item->context;
        $table->item_id = $item->id;

        if ($parentId) {
            $maxDepth = (int) $this->params->get('max_depth', 4);
            $parent   = $this->loadComment($parentId);

            if ($maxDepth < 1 || !$parent || $parent->context !== $item->context || (int) $parent->item_id !== (int) $item->id
                || (int) $parent->state !== YapHelper::STATE_PUBLISHED) {
                $this->fail('COM_YAPCOMMENTS_ERROR_BAD_PARENT', 400);
            }

            // Too deep: the reply joins the parent's own level instead.
            if ((int) $parent->depth >= $maxDepth && (int) $parent->parent_id) {
                $parent = $this->loadComment((int) $parent->parent_id) ?? $parent;
            }

            $table->parent_id = (int) $parent->id;
            $table->root_id   = (int) ($parent->root_id ?: $parent->id);
            $table->depth     = min((int) $parent->depth + 1, $maxDepth);
        }

        if ($user->guest) {
            [$table->name, $table->email] = $this->guestIdentity((string) ($input['name'] ?? ''), (string) ($input['email'] ?? ''));
        } else {
            $table->user_id = (int) $user->id;
            $table->name    = (string) $user->name;
            $table->email   = (string) $user->email;
        }

        $ip    = YapHelper::clientIp();
        $body  = $this->cleanBody((string) ($input['body'] ?? ''), $bypass, $held);
        $links = YapHelper::countLinks($body);

        if (!$bypass) {
            $this->assertRate($user, $ip);
        }

        if ($this->params->get('duplicate_check', 1) && $this->isDuplicate($body, $user, $ip, $item)) {
            $this->fail('COM_YAPCOMMENTS_ERROR_DUPLICATE', 409);
        }

        $holdLinks  = (int) $this->params->get('hold_links', 0);
        $autoApprove = $moderator || YapHelper::can('autoapprove', $user);

        if ($held || ($holdLinks > 0 && $links >= $holdLinks && !$moderator)) {
            $autoApprove = false;
        }

        $table->body       = $body;
        $table->flags      = YapHelper::flagsFor($user);
        $table->state      = $autoApprove ? YapHelper::STATE_PUBLISHED : YapHelper::STATE_PENDING;
        $table->ip         = YapHelper::storableIp($ip);
        $table->user_agent = mb_substr((string) Factory::getApplication()->getInput()->server->getString('HTTP_USER_AGENT', ''), 0, 255);
        $table->item_title = mb_substr($item->title, 0, 255);
        $table->item_url   = mb_substr($item->url, 0, 1024);
        $table->language   = $item->language ?: '*';
        $table->created    = Factory::getDate()->toSql();

        if (!$table->check() || !$table->store()) {
            throw new \RuntimeException($table->getError() ?: Text::_('COM_YAPCOMMENTS_ERROR_SAVE'), 500);
        }

        $session = Factory::getApplication()->getSession();
        $session->set('yapcomments.posted', \array_slice([...(array) $session->get('yapcomments.posted', []), (int) $table->id], -50));

        try {
            (new Notifier($this->params))->commentCreated($table, $parent);
        } catch (\Throwable) {
            // Never fail a post because mail could not be sent.
        }

        return $table;
    }

    /**
     * Edit the text of a comment.
     */
    public function update(int $id, string $body, User $user): CommentTable
    {
        $table     = $this->loadOrFail($id);
        $moderator = YapHelper::can('moderate', $user);

        if (!$moderator && !$this->canEditOwn($table, $user)) {
            $this->fail('COM_YAPCOMMENTS_ERROR_NO_EDIT', 403);
        }

        $bypass = $moderator || YapHelper::can('bypass.limits', $user);
        $body   = $this->cleanBody($body, $bypass, $held);

        $table->body        = $body;
        $table->modified    = Factory::getDate()->toSql();
        $table->modified_by = (int) $user->id;
        $table->edit_count  = (int) $table->edit_count + 1;

        // Authors re-write with their current abilities; moderators keep the author's.
        if ((int) $table->user_id === (int) $user->id) {
            $table->flags = YapHelper::flagsFor($user) | ((int) $table->flags & YapHelper::FLAG_DELETED);
        }

        if ($held && !$moderator) {
            $table->state = YapHelper::STATE_PENDING;
        }

        if (!$table->check() || !$table->store()) {
            throw new \RuntimeException($table->getError() ?: Text::_('COM_YAPCOMMENTS_ERROR_SAVE'), 500);
        }

        return $table;
    }

    /**
     * Delete a comment. Comments with replies are blanked so the thread survives.
     *
     * @return  bool  True when the row was removed, false when it was blanked.
     */
    public function remove(int $id, User $user): bool
    {
        $table = $this->loadOrFail($id);
        $own   = !$user->guest && (int) $table->user_id === (int) $user->id && YapHelper::can('delete.own', $user);

        if (!$own && !YapHelper::can('moderate', $user)) {
            $this->fail('COM_YAPCOMMENTS_ERROR_NO_DELETE', 403);
        }

        if ($this->hasReplies($id)) {
            $table->body     = '';
            $table->flags    = (int) $table->flags | YapHelper::FLAG_DELETED;
            $table->pinned   = 0;
            $table->modified = Factory::getDate()->toSql();
            $table->store();

            return false;
        }

        $table->delete($id);

        return true;
    }

    /**
     * Cast, change or withdraw (0) a vote.
     *
     * @return  array{likes: int, dislikes: int, vote: int}
     */
    public function vote(int $id, int $value, object $item, User $user): array
    {
        if (!$this->params->get('votes_enabled', 1) || !YapHelper::can('vote', $user)) {
            $this->fail('COM_YAPCOMMENTS_ERROR_NO_VOTE', 403);
        }

        if ($value === -1 && !$this->params->get('dislikes_enabled', 1)) {
            $this->fail('COM_YAPCOMMENTS_ERROR_NO_VOTE', 403);
        }

        $this->assertOpen($item);

        $table = $this->loadOrFail($id);

        if ((int) $table->state !== YapHelper::STATE_PUBLISHED || ((int) $table->flags & YapHelper::FLAG_DELETED)
            || $table->context !== $item->context || (int) $table->item_id !== (int) $item->id) {
            $this->fail('COM_YAPCOMMENTS_ERROR_NOT_FOUND', 404);
        }

        if (!$user->guest && (int) $table->user_id === (int) $user->id && !$this->params->get('self_vote', 0)) {
            $this->fail('COM_YAPCOMMENTS_ERROR_SELF_VOTE', 403);
        }

        $db    = $this->getDatabase();
        $voter = YapHelper::voterKey($user);
        $uid   = (int) $user->id;
        $now   = Factory::getDate()->toSql();

        $db->transactionStart();

        try {
            $db->setQuery(
                $db->createQuery()
                    ->delete($db->quoteName('#__yapcomments_votes'))
                    ->where($db->quoteName('comment_id') . ' = :cid')
                    ->where($db->quoteName('voter') . ' = :voter')
                    ->bind(':cid', $id, ParameterType::INTEGER)
                    ->bind(':voter', $voter)
            )->execute();

            if ($value !== 0) {
                $db->setQuery(
                    $db->createQuery()
                        ->insert($db->quoteName('#__yapcomments_votes'))
                        ->columns($db->quoteName(['comment_id', 'voter', 'user_id', 'vote', 'created']))
                        ->values(':cid, :voter, :uid, :vote, :created')
                        ->bind(':cid', $id, ParameterType::INTEGER)
                        ->bind(':voter', $voter)
                        ->bind(':uid', $uid, ParameterType::INTEGER)
                        ->bind(':vote', $value, ParameterType::INTEGER)
                        ->bind(':created', $now)
                )->execute();
            }

            $counts = $db->setQuery(
                $db->createQuery()
                    ->select([
                        'COALESCE(SUM(CASE WHEN ' . $db->quoteName('vote') . ' > 0 THEN 1 ELSE 0 END), 0) AS likes',
                        'COALESCE(SUM(CASE WHEN ' . $db->quoteName('vote') . ' < 0 THEN 1 ELSE 0 END), 0) AS dislikes',
                    ])
                    ->from($db->quoteName('#__yapcomments_votes'))
                    ->where($db->quoteName('comment_id') . ' = :cid')
                    ->bind(':cid', $id, ParameterType::INTEGER)
            )->loadObject();

            $likes    = (int) $counts->likes;
            $dislikes = (int) $counts->dislikes;

            $db->setQuery(
                $db->createQuery()
                    ->update($db->quoteName('#__yapcomments_comments'))
                    ->set($db->quoteName('likes') . ' = :likes')
                    ->set($db->quoteName('dislikes') . ' = :dislikes')
                    ->where($db->quoteName('id') . ' = :cid')
                    ->bind(':likes', $likes, ParameterType::INTEGER)
                    ->bind(':dislikes', $dislikes, ParameterType::INTEGER)
                    ->bind(':cid', $id, ParameterType::INTEGER)
            )->execute();

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw new \RuntimeException(Text::_('COM_YAPCOMMENTS_ERROR_SAVE'), 500, $e);
        }

        return ['likes' => $likes, 'dislikes' => $dislikes, 'vote' => $value];
    }

    /**
     * Front-end moderation.
     *
     * @return  CommentTable|null  The updated row, or null when it was deleted.
     */
    public function moderate(int $id, string $action, User $user): ?CommentTable
    {
        if (!YapHelper::can('moderate', $user)) {
            $this->fail('COM_YAPCOMMENTS_ERROR_NO_MODERATE', 403);
        }

        $table = $this->loadOrFail($id);

        switch ($action) {
            case 'approve':
                $table->state = YapHelper::STATE_PUBLISHED;
                break;
            case 'unapprove':
                $table->state = YapHelper::STATE_PENDING;
                break;
            case 'spam':
                $table->state = YapHelper::STATE_SPAM;
                break;
            case 'pin':
            case 'unpin':
                $table->pinned = $action === 'pin' ? 1 : 0;
                break;
            case 'delete':
                $table->delete($id);

                return null;
            default:
                $this->fail('COM_YAPCOMMENTS_ERROR_BAD_REQUEST', 400);
        }

        $table->store();

        return $table;
    }

    /**
     * Raw text of a comment for the inline editor.
     */
    public function source(int $id, User $user): string
    {
        $table = $this->loadOrFail($id);

        if (!YapHelper::can('moderate', $user) && !$this->canEditOwn($table, $user)) {
            $this->fail('COM_YAPCOMMENTS_ERROR_NO_EDIT', 403);
        }

        return (string) $table->body;
    }

    public function canEditOwn(object $row, User $user): bool
    {
        if ($user->guest || (int) $row->user_id !== (int) $user->id || !YapHelper::can('edit.own', $user)
            || ((int) $row->flags & YapHelper::FLAG_DELETED)) {
            return false;
        }

        $window = (int) $this->params->get('edit_window', 15);

        return $window === 0 || Factory::getDate($row->created)->toUnix() > time() - $window * 60;
    }

    /**
     * Render text the way it would appear if posted by this user.
     */
    public function preview(string $body, User $user): string
    {
        return (new MarkdownRenderer($this->params))->render(mb_substr($body, 0, (int) $this->params->get('max_length', 5000)), YapHelper::flagsFor($user));
    }

    public function getTable($name = 'Comment', $prefix = 'Administrator', $options = [])
    {
        return Factory::getApplication()->bootComponent('com_yapcomments')->getMVCFactory()->createTable($name, $prefix, $options);
    }

    private function loadComment(int $id): ?CommentTable
    {
        $table = $this->getTable();

        return $id > 0 && $table->load($id) ? $table : null;
    }

    private function loadOrFail(int $id): CommentTable
    {
        return $this->loadComment($id) ?? $this->fail('COM_YAPCOMMENTS_ERROR_NOT_FOUND', 404);
    }

    private function assertOpen(object $item): void
    {
        if ($item->mode !== ContextResolver::MODE_OPEN || !$this->params->get('enabled', 1)) {
            $this->fail('COM_YAPCOMMENTS_ERROR_LOCKED', 403);
        }
    }

    /**
     * Validate a guest's name and e-mail.
     *
     * @return  array{0: string, 1: string}
     */
    private function guestIdentity(string $name, string $email): array
    {
        $name  = trim(preg_replace('/\s+/u', ' ', strip_tags(MarkdownRenderer::normalize($name))));
        // The field is only shown when an address is required; otherwise none is collected.
        $email = $this->params->get('guest_require_email', 0) ? trim($email) : '';

        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            $this->fail('COM_YAPCOMMENTS_ERROR_NAME', 422);
        }

        if ($this->params->get('guest_require_email', 0) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('COM_YAPCOMMENTS_ERROR_EMAIL', 422);
        }

        if ($this->params->get('guest_protect_names', 1)) {
            $db    = $this->getDatabase();
            $taken = (int) $db->setQuery(
                $db->createQuery()
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__users'))
                    ->where('(LOWER(' . $db->quoteName('name') . ') = LOWER(:n1) OR LOWER(' . $db->quoteName('username') . ') = LOWER(:n2))')
                    ->bind(':n1', $name)
                    ->bind(':n2', $name)
            )->loadResult();

            if ($taken) {
                $this->fail('COM_YAPCOMMENTS_ERROR_NAME_TAKEN', 422);
            }
        }

        return [$name, $email];
    }

    /**
     * Normalise and validate comment text; applies the bad-word policy.
     *
     * @param   bool|null  $held  Set to true when the text must wait for moderation.
     */
    private function cleanBody(string $body, bool $bypass, ?bool &$held = null): string
    {
        $held = false;
        $body = MarkdownRenderer::normalize($body);
        $len  = mb_strlen($body);

        if ($len < (int) $this->params->get('min_length', 3)) {
            $this->fail('COM_YAPCOMMENTS_ERROR_TOO_SHORT', 422, (int) $this->params->get('min_length', 3));
        }

        if ($len > (int) $this->params->get('max_length', 5000)) {
            $this->fail('COM_YAPCOMMENTS_ERROR_TOO_LONG', 422, (int) $this->params->get('max_length', 5000));
        }

        if (!$bypass && YapHelper::countLinks($body) > (int) $this->params->get('max_links', 3)) {
            $this->fail('COM_YAPCOMMENTS_ERROR_TOO_MANY_LINKS', 422, (int) $this->params->get('max_links', 3));
        }

        $words = array_filter(array_map('trim', preg_split('/[\n,]+/', (string) $this->params->get('badwords', ''))));

        if ($words) {
            $pattern = '/(?<![\p{L}\p{N}])(' . implode('|', array_map(static fn ($w) => preg_quote($w, '/'), $words)) . ')(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $body)) {
                switch ($this->params->get('badwords_action', 'censor')) {
                    case 'reject':
                        $this->fail('COM_YAPCOMMENTS_ERROR_BADWORDS', 422);
                        // no break
                    case 'moderate':
                        $held = true;
                        break;
                    default:
                        $body = preg_replace_callback($pattern, static fn ($m) => mb_substr($m[1], 0, 1) . str_repeat('*', max(1, mb_strlen($m[1]) - 1)), $body);
                }
            }
        }

        return $body;
    }

    /**
     * Flood interval and hourly cap, by account or by address for guests.
     */
    private function assertRate(User $user, string $ip): void
    {
        $flood  = (int) $this->params->get('flood_interval', 30);
        $hourly = (int) $this->params->get('hourly_limit', 20);

        if (!$flood && !$hourly) {
            return;
        }

        $db    = $this->getDatabase();
        $since = Factory::getDate('-1 hour')->toSql();
        $query = $db->createQuery()
            ->select(['COUNT(*) AS total', 'MAX(' . $db->quoteName('created') . ') AS latest'])
            ->from($db->quoteName('#__yapcomments_comments'))
            ->where($db->quoteName('created') . ' >= :since')
            ->bind(':since', $since);

        $this->whereAuthor($query, $user, $ip);

        $row = $db->setQuery($query)->loadObject();

        if ($hourly && (int) $row->total >= $hourly) {
            $this->fail('COM_YAPCOMMENTS_ERROR_HOURLY', 429);
        }

        if ($flood && $row->latest && Factory::getDate($row->latest)->toUnix() > time() - $flood) {
            $this->fail('COM_YAPCOMMENTS_ERROR_FLOOD', 429, $flood);
        }
    }

    private function isDuplicate(string $body, User $user, string $ip, object $item): bool
    {
        $db    = $this->getDatabase();
        $since = Factory::getDate('-1 day')->toSql();
        $query = $db->createQuery()
            ->select('COUNT(*)')
            ->from($db->quoteName('#__yapcomments_comments'))
            ->where($db->quoteName('context') . ' = :context')
            ->where($db->quoteName('item_id') . ' = :item')
            ->where($db->quoteName('body') . ' = :body')
            ->where($db->quoteName('created') . ' >= :since')
            ->bind(':context', $item->context)
            ->bind(':item', $item->id, ParameterType::INTEGER)
            ->bind(':body', $body)
            ->bind(':since', $since);

        $this->whereAuthor($query, $user, $ip);

        return (int) $db->setQuery($query)->loadResult() > 0;
    }

    private function whereAuthor($query, User $user, string $ip): void
    {
        $db = $this->getDatabase();

        if (!$user->guest) {
            $uid = (int) $user->id;
            $query->where($db->quoteName('user_id') . ' = :author')->bind(':author', $uid, ParameterType::INTEGER);

            return;
        }

        $stored = YapHelper::storableIp($ip);

        if ($stored === '') {
            // Addresses are not stored: fall back to the session.
            $sessionIds = (array) Factory::getApplication()->getSession()->get('yapcomments.posted', []);
            $query->whereIn($db->quoteName('id'), $sessionIds ?: [0]);

            return;
        }

        $query->where($db->quoteName('ip') . ' = :ip')->where($db->quoteName('user_id') . ' = 0')->bind(':ip', $stored);
    }

    private function hasReplies(int $id): bool
    {
        $db = $this->getDatabase();

        return (int) $db->setQuery(
            $db->createQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName('#__yapcomments_comments'))
                ->where($db->quoteName('parent_id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER)
        )->loadResult() > 0;
    }

    /**
     * @return never
     */
    private function fail(string $key, int $code, ...$args): never
    {
        throw new \RuntimeException($args ? Text::sprintf($key, ...$args) : Text::_($key), $code);
    }
}
