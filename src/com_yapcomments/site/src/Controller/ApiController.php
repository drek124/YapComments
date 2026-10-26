<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Site\Controller;

\defined('_JEXEC') or die;

use Drek124\Component\YapComments\Administrator\Helper\YapHelper;
use Drek124\Component\YapComments\Site\Helper\ContextResolver;
use Drek124\Component\YapComments\Site\Helper\ThreadRenderer;
use Drek124\Component\YapComments\Site\Model\PostModel;
use Joomla\CMS\Captcha\Captcha;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;

/**
 * JSON endpoint used by the comments script:
 * index.php?option=com_yapcomments&format=json&task=api.<action>
 */
class ApiController extends BaseController
{
    /**
     * A page of comments.
     */
    public function load(): void
    {
        $this->handle(function (): array {
            $item     = $this->item();
            $renderer = new ThreadRenderer($item, $this->user());

            if (!$renderer->ctx->canView || $item->mode === ContextResolver::MODE_HIDDEN) {
                throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }

            $data = $renderer->page($this->input->getInt('page', 1), $renderer->sort($this->input->getCmd('sort')));
            unset($data['items']);

            return $data;
        }, false);
    }

    /**
     * Post a new comment or reply.
     */
    public function post(): void
    {
        $this->handle(function (): array {
            $item   = $this->item();
            $user   = $this->user();
            $params = YapHelper::params();

            $this->assertHuman($item, $user);

            $table = $this->postModel()->create([
                'parent_id' => $this->input->post->getInt('parent_id', 0),
                'body'      => $this->input->post->get('body', '', 'raw'),
                'name'      => $this->input->post->getString('name', ''),
                'email'     => $this->input->post->getString('email', ''),
            ], $item, $user);

            $renderer = new ThreadRenderer($item, $user);
            $row      = $this->threadRow((int) $table->id, $user);
            $pending  = (int) $table->state === YapHelper::STATE_PENDING;

            return [
                'id'       => (int) $table->id,
                'parentId' => (int) $table->parent_id,
                'pending'  => $pending,
                'html'     => $row ? $renderer->renderComment($row) : '',
                'count'    => $this->threadModel()->countPublished($item->context, $item->id),
                'stamp'    => ThreadRenderer::stamp($item),
                'message'  => Text::_($pending ? 'COM_YAPCOMMENTS_JS_POSTED_PENDING' : 'COM_YAPCOMMENTS_JS_POSTED'),
                'captcha'  => $params->get('captcha') !== '',
            ];
        });
    }

    /**
     * Render text as it would look when posted.
     */
    public function preview(): void
    {
        $this->handle(fn (): array => [
            'html' => $this->postModel()->preview($this->input->post->get('body', '', 'raw'), $this->user()),
        ]);
    }

    /**
     * Raw text for the inline editor.
     */
    public function source(): void
    {
        // Read-only lookup; the model still checks the viewer may edit this comment.
        $this->handle(fn (): array => [
            'body' => $this->postModel()->source($this->input->getInt('id'), $this->user()),
        ], false);
    }

    /**
     * Save an edited comment.
     */
    public function edit(): void
    {
        $this->handle(function (): array {
            $item  = $this->item();
            $user  = $this->user();
            $table = $this->postModel()->update($this->input->post->getInt('id'), $this->input->post->get('body', '', 'raw'), $user);

            return ['html' => $this->renderSingle($item, (int) $table->id, $user)];
        });
    }

    /**
     * Delete a comment.
     */
    public function remove(): void
    {
        $this->handle(function (): array {
            $item    = $this->item();
            $user    = $this->user();
            $id      = $this->input->post->getInt('id');
            $removed = $this->postModel()->remove($id, $user);

            return [
                'removed' => $removed,
                'html'    => $removed ? '' : $this->renderSingle($item, $id, $user),
                'count'   => $this->threadModel()->countPublished($item->context, $item->id),
                'message' => Text::_('COM_YAPCOMMENTS_JS_DELETED'),
            ];
        });
    }

    /**
     * Like (1), dislike (-1) or withdraw a vote (0).
     */
    public function vote(): void
    {
        $this->handle(function (): array {
            $value = max(-1, min(1, $this->input->post->getInt('vote', 0)));

            return $this->postModel()->vote($this->input->post->getInt('id'), $value, $this->item(), $this->user());
        });
    }

    /**
     * Approve, unapprove, spam, pin, unpin or delete.
     */
    public function moderate(): void
    {
        $this->handle(function (): array {
            $item   = $this->item();
            $user   = $this->user();
            $id     = $this->input->post->getInt('id');
            $action = $this->input->post->getCmd('action');
            $table  = $this->postModel()->moderate($id, $action, $user);

            $visible = $table && \in_array((int) $table->state, [YapHelper::STATE_PUBLISHED, YapHelper::STATE_PENDING], true);

            return [
                'removed' => !$visible,
                'html'    => $visible ? $this->renderSingle($item, $id, $user) : '',
                'count'   => $this->threadModel()->countPublished($item->context, $item->id),
            ];
        });
    }

    /**
     * Run an action and emit a JSON response, mapping exceptions to HTTP codes.
     */
    private function handle(callable $action, bool $write = true): void
    {
        $app = $this->app;

        try {
            if ($write) {
                if (strtoupper($this->input->getMethod()) !== 'POST') {
                    throw new \RuntimeException(Text::_('COM_YAPCOMMENTS_ERROR_BAD_REQUEST'), 405);
                }

                if (!Session::checkToken('post')) {
                    throw new \RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'), 403);
                }
            }

            if (!YapHelper::params()->get('enabled', 1)) {
                throw new \RuntimeException(Text::_('COM_YAPCOMMENTS_ERROR_DISABLED'), 403);
            }

            $response = new JsonResponse($action());
        } catch (\RuntimeException $e) {
            $code     = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            $response = new JsonResponse(null, $e->getMessage(), true);
            $app->setHeader('status', $code, true);
        } catch (\Throwable $e) {
            $response = new JsonResponse(null, Text::_('COM_YAPCOMMENTS_ERROR_SAVE'), true);
            $app->setHeader('status', 500, true);
        }

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->setHeader('X-Robots-Tag', 'noindex', true);
        $app->sendHeaders();

        echo $response;

        $app->close();
    }

    /**
     * The item the request refers to, with access checks applied.
     */
    private function item(): object
    {
        $context = $this->input->getCmd('context', 'com_content.article');
        $context = preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $context) ? $context : '';
        $item    = $context !== '' ? ContextResolver::resolve($context, $this->input->getInt('item'), $this->user()) : null;

        if (!$item) {
            throw new \RuntimeException(Text::_('COM_YAPCOMMENTS_ERROR_NOT_FOUND'), 404);
        }

        return $item;
    }

    /**
     * Honeypot, minimum fill time and captcha.
     */
    private function assertHuman(object $item, User $user): void
    {
        $params = YapHelper::params();

        if ($params->get('honeypot', 1) && $this->input->post->getString('yap_url', '') !== '') {
            throw new \RuntimeException(Text::_('COM_YAPCOMMENTS_ERROR_SPAM'), 422);
        }

        $age = ThreadRenderer::stampAge($this->input->post->getString('yap_ts', ''), $item);

        if ($age < 0 || $age > 86400) {
            throw new \RuntimeException(Text::_('COM_YAPCOMMENTS_ERROR_EXPIRED'), 422);
        }

        if ($age < (int) $params->get('min_submit_time', 3) && !YapHelper::can('bypass.limits', $user)) {
            throw new \RuntimeException(Text::_('COM_YAPCOMMENTS_ERROR_TOO_FAST'), 429);
        }

        $plugin = (string) $params->get('captcha', '');

        if ($plugin !== '' && ($user->guest || !$params->get('captcha_guests_only', 1))) {
            try {
                $captcha = Captcha::getInstance($plugin, ['namespace' => 'yapcomments']);
            } catch (\Throwable) {
                $captcha = null;
            }

            if ($captcha && !$captcha->checkAnswer($this->input->post->get('yap_captcha', '', 'raw'))) {
                throw new \RuntimeException(Text::_('COM_YAPCOMMENTS_ERROR_CAPTCHA'), 422);
            }
        }
    }

    private function renderSingle(object $item, int $id, User $user): string
    {
        $row = $this->threadRow($id, $user);

        return $row ? (new ThreadRenderer($item, $user))->renderComment($row) : '';
    }

    private function threadRow(int $id, User $user): ?object
    {
        return $this->threadModel()->getComment($id, (int) $user->id, YapHelper::can('moderate', $user))
            ?? (\in_array($id, (array) $this->app->getSession()->get('yapcomments.posted', []), true) ? $this->threadModel()->getComment($id, 0, true) : null);
    }

    private function user(): User
    {
        return $this->app->getIdentity() ?? new User();
    }

    private function postModel(): PostModel
    {
        return $this->getModel('Post', 'Site', ['ignore_request' => true]);
    }

    private function threadModel()
    {
        return $this->getModel('Thread', 'Site', ['ignore_request' => true]);
    }
}
