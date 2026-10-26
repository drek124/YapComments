<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Site\Helper;

\defined('_JEXEC') or die;

use Drek124\Component\YapComments\Administrator\Helper\MarkdownRenderer;
use Drek124\Component\YapComments\Administrator\Helper\YapHelper;
use Drek124\Component\YapComments\Site\Model\PostModel;
use Drek124\Component\YapComments\Site\Model\ThreadModel;
use Joomla\CMS\Captcha\Captcha;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

/**
 * Turns comment rows into HTML through overridable layouts
 * (templates/YOUR_TEMPLATE/html/layouts/com_yapcomments/yapcomments/*.php).
 *
 * The same layouts are used for the first server-side render (crawlable,
 * works without JavaScript) and for every AJAX response.
 */
final class ThreadRenderer
{
    public const SORTS = ['newest', 'oldest', 'top'];

    /** Everything the layouts need to know about the viewer and the thread. */
    public object $ctx;

    private Registry $params;

    private ThreadModel $model;

    private PostModel $post;

    private MarkdownRenderer $markdown;

    /** @var array<int, int> */
    private array $votes = [];

    /** @var array<int, true>  Authors in the Administrator or Super Users group. */
    private array $admins = [];

    /** @var array<int, true>  Comments whose votes and author status are loaded. */
    private array $loaded = [];

    public function __construct(private object $item, ?User $user = null)
    {
        $app          = Factory::getApplication();
        $user       ??= $app->getIdentity() ?? new User();
        $factory      = $app->bootComponent('com_yapcomments')->getMVCFactory();
        $this->params = YapHelper::params();
        $this->model  = $factory->createModel('Thread', 'Site', ['ignore_request' => true]);
        $this->post   = $factory->createModel('Post', 'Site', ['ignore_request' => true]);

        $this->markdown = new MarkdownRenderer($this->params);

        $open      = $item->mode === ContextResolver::MODE_OPEN;
        $moderator = YapHelper::can('moderate', $user);
        $votes     = (bool) $this->params->get('votes_enabled', 1);

        $this->ctx = (object) [
            'item'       => $item,
            'user'       => $user,
            'params'     => $this->params,
            'thread'     => $this,
            'open'       => $open,
            'moderator'  => $moderator,
            'canView'    => $moderator || YapHelper::can('view', $user),
            'canPost'    => $open && YapHelper::can('post', $user),
            'canReply'   => $open && (int) $this->params->get('max_depth', 4) > 0 && YapHelper::can('reply', $user),
            'canVote'    => $open && $votes && YapHelper::can('vote', $user),
            'votes'      => $votes,
            'dislikes'   => $votes && $this->params->get('dislikes_enabled', 1),
            'score'      => $this->params->get('vote_display', 'separate') === 'score',
            'flags'      => YapHelper::flagsFor($user),
            'microdata'  => (bool) $this->params->get('microdata', 1),
            'collapse'   => (int) $this->params->get('collapse_threshold', -5),
            'perPage'    => max(1, (int) $this->params->get('per_page', 10)),
            'replyLimit' => 3,
        ];
    }

    /**
     * The complete comments section for an item.
     */
    public function block(int $page = 1, ?string $sort = null): string
    {
        if (!$this->ctx->canView || $this->item->mode === ContextResolver::MODE_HIDDEN) {
            return '';
        }

        $sort = $this->sort($sort);
        $data = $this->page($page, $sort, true);

        $this->loadAssets();

        return $this->layout('yapcomments.block', [
            'ctx'      => $this->ctx,
            'sort'     => $sort,
            'page'     => $data,
            'composer' => $this->composer(),
            'config'   => $this->config($sort, $data),
        ]);
    }

    /**
     * One page of top-level comments, rendered.
     *
     * @return array{html: string, items: object[], total: int, count: int, page: int, hasMore: bool, next: string}
     */
    public function page(int $page, string $sort, bool $cumulative = false): array
    {
        $page  = max(1, $page);
        $limit = $this->ctx->perPage;

        // A crawler following ?ycp=3 without JavaScript gets pages 1-3 at once.
        $data = $cumulative
            ? $this->model->getPage($this->item->context, $this->item->id, $sort, 1, $limit * $page, (int) $this->ctx->user->id, $this->ctx->moderator)
            : $this->model->getPage($this->item->context, $this->item->id, $sort, $page, $limit, (int) $this->ctx->user->id, $this->ctx->moderator);

        $this->loadMeta($data['items']);

        $html = '';

        foreach ($data['items'] as $row) {
            $html .= $this->renderComment($row);
        }

        $hasMore = $data['total'] > $page * $limit;

        return [
            'html'    => $html,
            'items'   => $data['items'],
            'total'   => $data['total'],
            'count'   => $this->model->countPublished($this->item->context, $this->item->id),
            'page'    => $page,
            'hasMore' => $hasMore,
            'next'    => $hasMore ? $this->pageUrl($page + 1) : '',
        ];
    }

    /**
     * Render one comment (and its replies) from a database row.
     */
    public function renderComment(object $row): string
    {
        $this->loadMeta([$row]);

        return $this->layout('yapcomments.comment', ['c' => $this->present($row), 'ctx' => $this->ctx]);
    }

    /**
     * Render a presented child comment; used by the comment layout for recursion.
     */
    public function renderPresented(object $comment): string
    {
        return $this->layout('yapcomments.comment', ['c' => $comment, 'ctx' => $this->ctx]);
    }

    /**
     * Prepare a row for the templates.
     */
    public function present(object $row): object
    {
        $ctx      = $this->ctx;
        $user     = $ctx->user;
        $deleted  = ((int) $row->flags & YapHelper::FLAG_DELETED) === YapHelper::FLAG_DELETED;
        $name     = $row->user_id && !empty($row->user_name) ? $row->user_name : $row->name;
        $email    = $row->user_id && !empty($row->user_email) ? $row->user_email : $row->email;
        $own      = !$user->guest && (int) $row->user_id === (int) $user->id;
        $likes    = (int) $row->likes;
        $dislikes = (int) $row->dislikes;
        $score    = $likes - $dislikes;
        $relative = $this->params->get('date_format', 'relative') === 'relative';

        $c = (object) [
            'id'          => (int) $row->id,
            'parentId'    => (int) $row->parent_id,
            'depth'       => (int) $row->depth,
            'author'      => $deleted ? Text::_('COM_YAPCOMMENTS_DELETED_AUTHOR') : $name,
            'avatar'      => $deleted ? ['type' => 'none'] : YapHelper::avatar((string) $name, (string) $email),
            'isGuest'     => !(int) $row->user_id,
            'isAdmin'     => !$deleted && isset($this->admins[(int) $row->user_id]),
            'isOp'        =>(int) $row->user_id > 0 && (int) $row->user_id === (int) $this->item->author_id,
            'isOwn'       => $own,
            'isPending'   => (int) $row->state === YapHelper::STATE_PENDING,
            'isPinned'    => (bool) $row->pinned && !$deleted,
            'isDeleted'   => $deleted,
            'isEdited'    => (int) $row->edit_count > 0,
            'html'        => $deleted ? '' : $this->markdown->render((string) $row->body, (int) $row->flags),
            'iso'         => Factory::getDate($row->created)->format('c'),
            'date'        => $relative ? HTMLHelper::_('date.relative', $row->created) : HTMLHelper::_('date', $row->created, Text::_('DATE_FORMAT_LC2')),
            'fullDate'    => HTMLHelper::_('date', $row->created, Text::_('DATE_FORMAT_LC2')),
            'likes'       => $likes,
            'dislikes'    => $dislikes,
            'score'       => $score,
            'myVote'      => $this->votes[(int) $row->id] ?? 0,
            'collapsed'   => $ctx->votes && $ctx->collapse < 0 && $score <= $ctx->collapse,
            'permalink'   => $this->item->url . '#comment-' . (int) $row->id,
            'canReply'    => $ctx->canReply && !$deleted && (int) $row->state === YapHelper::STATE_PUBLISHED,
            'canVote'     => $ctx->canVote && !$deleted && (int) $row->state === YapHelper::STATE_PUBLISHED
                && (!$own || $this->params->get('self_vote', 0)),
            'canEdit'     => !$deleted && ($ctx->moderator || ($ctx->open && $this->post->canEditOwn($row, $user))),
            'canDelete'   => $ctx->moderator || ($own && !$deleted && YapHelper::can('delete.own', $user)),
            'canModerate' => $ctx->moderator,
            'children'    => [],
        ];

        foreach ($row->children ?? [] as $child) {
            $c->children[] = $this->present($child);
        }

        return $c;
    }

    /**
     * The post form, or a message explaining why there is none.
     */
    public function composer(): string
    {
        $ctx = $this->ctx;

        if (!$ctx->open) {
            return $this->layout('yapcomments.notice', ['ctx' => $ctx, 'type' => 'locked']);
        }

        $notice = '';

        if (!$ctx->canPost) {
            $notice = $this->layout('yapcomments.notice', [
                'ctx'   => $ctx,
                'type'  => $ctx->user->guest ? 'login' : 'denied',
                'login' => Route::_('index.php?option=com_users&view=login&return=' . base64_encode($this->item->url . '#comments')),
            ]);

            if (!$ctx->canReply) {
                return $notice;
            }
        }

        // Users who may reply but not start threads get a form that only appears under comments.
        return $notice . $this->layout('yapcomments.composer', [
            'ctx'       => $ctx,
            'captcha'   => $this->captchaHtml(),
            'stamp'     => self::stamp($this->item),
            'replyOnly' => !$ctx->canPost,
        ]);
    }

    /**
     * A signed timestamp proving when the form was rendered.
     */
    public static function stamp(object $item): string
    {
        $time = (string) time();

        return $time . '.' . hash_hmac('sha256', $time . '|' . $item->context . '|' . $item->id, YapHelper::secret());
    }

    /**
     * Verify a stamp and return how many seconds ago it was issued (or -1).
     */
    public static function stampAge(string $stamp, object $item): int
    {
        [$time, $sig] = array_pad(explode('.', $stamp, 2), 2, '');

        if (!ctype_digit($time) || !hash_equals(hash_hmac('sha256', $time . '|' . $item->context . '|' . $item->id, YapHelper::secret()), $sig)) {
            return -1;
        }

        return time() - (int) $time;
    }

    /**
     * Captcha markup if one applies to this viewer.
     */
    public function captchaHtml(): string
    {
        $plugin = (string) $this->params->get('captcha', '');

        if ($plugin === '' || (!$this->ctx->user->guest && $this->params->get('captcha_guests_only', 1))) {
            return '';
        }

        try {
            $captcha = Captcha::getInstance($plugin, ['namespace' => 'yapcomments']);

            return $captcha ? (string) $captcha->display('yap_captcha', 'yap-captcha-' . $this->item->id, 'yap-captcha') : '';
        } catch (\Throwable) {
            return '';
        }
    }

    public function sort(?string $sort): string
    {
        return \in_array($sort, self::SORTS, true) ? $sort : (string) $this->params->get('order', 'newest');
    }

    public function pageUrl(int $page): string
    {
        $uri = new Uri($this->item->url);
        $uri->setVar('ycp', $page);
        $uri->setFragment('comments');

        return $uri->toString();
    }

    /**
     * Options handed to the script through a data attribute.
     */
    private function config(string $sort, array $data): array
    {
        $lang = Factory::getApplication()->getLanguage();

        return [
            'endpoint'   => Uri::base(true) . '/index.php?option=com_yapcomments&format=json',
            'context'    => $this->item->context,
            'item'       => (int) $this->item->id,
            'sort'       => $sort,
            'page'       => (int) $data['page'],
            'token'      => Session::getFormToken(),
            'minLength'  => (int) $this->params->get('min_length', 3),
            'maxLength'  => (int) $this->params->get('max_length', 5000),
            'relative'   => $this->params->get('date_format', 'relative') === 'relative',
            'animations' => (string) $this->params->get('animations', 'full'),
            'lang'       => $lang->getTag(),
            'rtl'        => (bool) $lang->isRtl(),
            'guest'      => (bool) $this->ctx->user->guest,
            'score'      => (bool) $this->ctx->score,
            'replyLimit' => $this->ctx->replyLimit,
            'flags'      => (int) $this->ctx->flags,
        ];
    }

    /**
     * Load the viewer's votes and the authors' admin status for rows (and their
     * replies) in two queries, skipping anything already loaded.
     */
    private function loadMeta(array $rows): void
    {
        $ids   = [];
        $users = [];
        $walk  = function (array $rows) use (&$walk, &$ids, &$users): void {
            foreach ($rows as $row) {
                if (!isset($this->loaded[(int) $row->id])) {
                    $this->loaded[(int) $row->id] = true;
                    $ids[]   = (int) $row->id;
                    $users[] = (int) $row->user_id;
                }

                $walk($row->children ?? []);
            }
        };
        $walk($rows);

        if (!$ids) {
            return;
        }

        if ($this->ctx->votes) {
            $this->votes = $this->votes + $this->model->getVotes($ids, YapHelper::voterKey($this->ctx->user));
        }

        $this->admins = $this->admins + $this->model->getAdministrators($users);
    }

    private function loadAssets(): void
    {
        $doc = Factory::getApplication()->getDocument();

        if (!method_exists($doc, 'getWebAssetManager')) {
            return;
        }

        $wa = $doc->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_yapcomments');
        $wa->useStyle('com_yapcomments.site')->useScript('com_yapcomments.site');

        foreach ([
            'POSTING', 'POSTED', 'POSTED_PENDING', 'ERROR', 'NETWORK_ERROR', 'CONFIRM_DELETE', 'DELETED', 'COPIED',
            'REPLYING_TO', 'SAVE', 'CANCEL', 'SAVING', 'SAVED', 'PREVIEW_EMPTY', 'LOADING', 'SHOW_REPLIES', 'HIDE_REPLIES',
            'CHARS_LEFT', 'TOO_SHORT', 'LINK_PROMPT', 'IMAGE_PROMPT', 'JUST_NOW', 'APPROVED', 'SPAMMED', 'PINNED', 'UNPINNED',
            'VOTE_LOGIN',
        ] as $key) {
            Text::script('COM_YAPCOMMENTS_JS_' . $key);
        }
    }

    private function layout(string $id, array $data): string
    {
        return (new FileLayout($id, null, ['component' => 'com_yapcomments', 'client' => 0]))->render($data);
    }
}
