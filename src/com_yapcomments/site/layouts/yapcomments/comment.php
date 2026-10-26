<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * A single comment with its replies.
 * Override: templates/YOUR_TEMPLATE/html/layouts/com_yapcomments/yapcomments/comment.php
 *
 * @var array $displayData {c, ctx}
 */

\defined('_JEXEC') or die;

use Drek124\Component\YapComments\Site\Helper\Icons;
use Joomla\CMS\Language\Text;

$c   = $displayData['c'];
$ctx = $displayData['ctx'];
$md  = $ctx->microdata && !$c->isPending && !$c->isDeleted;
$e   = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$classes = array_filter([
    'yap-comment',
    $c->isPending ? 'is-pending' : '',
    $c->isPinned ? 'is-pinned' : '',
    $c->isDeleted ? 'is-deleted' : '',
    $c->isOwn ? 'is-own' : '',
    $c->isOp ? 'is-op' : '',
    $c->collapsed ? 'is-collapsed' : '',
]);

$hasMenu = $c->canEdit || $c->canDelete || $c->canModerate;
?>
<article class="<?php echo implode(' ', $classes); ?>" id="comment-<?php echo $c->id; ?>" data-id="<?php echo $c->id; ?>" data-depth="<?php echo $c->depth; ?>" data-author="<?php echo $e($c->author); ?>"
    <?php if ($md) : ?>itemprop="comment" itemscope itemtype="https://schema.org/Comment"<?php endif; ?>>
    <div class="yap-comment__card">
        <div class="yap-comment__aside">
            <?php if ($c->avatar['type'] === 'image') : ?>
                <img class="yap-avatar" src="<?php echo $e($c->avatar['url']); ?>" alt="" width="40" height="40" loading="lazy" decoding="async">
            <?php elseif ($c->avatar['type'] === 'initials') : ?>
                <span class="yap-avatar" style="--hue: <?php echo (int) $c->avatar['hue']; ?>" aria-hidden="true"><?php echo $e($c->avatar['initials']); ?></span>
            <?php else : ?>
                <span class="yap-avatar yap-avatar--empty" aria-hidden="true"></span>
            <?php endif; ?>
            <?php if ($c->children) : ?>
                <button type="button" class="yap-thread-line" data-yap-collapse aria-expanded="true" aria-label="<?php echo Text::_('COM_YAPCOMMENTS_COLLAPSE_THREAD'); ?>"></button>
            <?php endif; ?>
        </div>

        <div class="yap-comment__content">
            <header class="yap-comment__header">
                <span class="yap-author"<?php echo $md ? ' itemprop="author" itemscope itemtype="https://schema.org/Person"' : ''; ?>>
                    <?php if ($c->isAdmin) : ?>
                        <span class="yap-admin-shield" role="img" title="<?php echo Text::_('COM_YAPCOMMENTS_BADGE_ADMIN'); ?>" aria-label="<?php echo Text::_('COM_YAPCOMMENTS_BADGE_ADMIN'); ?>"><?php echo Icons::svg('shield'); ?></span>
                    <?php endif; ?>
                    <span<?php echo $md ? ' itemprop="name"' : ''; ?>><?php echo $e($c->author); ?></span>
                </span>
                <?php if ($c->isOp) : ?>
                    <span class="yap-badge yap-badge--op" title="<?php echo Text::_('COM_YAPCOMMENTS_BADGE_OP_DESC'); ?>"><?php echo Text::_('COM_YAPCOMMENTS_BADGE_OP'); ?></span>
                <?php endif; ?>
                <?php if ($c->isGuest && !$c->isDeleted) : ?>
                    <span class="yap-badge yap-badge--guest"><?php echo Text::_('COM_YAPCOMMENTS_GUEST'); ?></span>
                <?php endif; ?>
                <?php if ($c->isPinned) : ?>
                    <span class="yap-badge yap-badge--pinned"><?php echo Icons::svg('pin'); ?><?php echo Text::_('COM_YAPCOMMENTS_PINNED'); ?></span>
                <?php endif; ?>
                <?php if ($c->isPending) : ?>
                    <span class="yap-badge yap-badge--pending"><?php echo Icons::svg('clock'); ?><?php echo Text::_('COM_YAPCOMMENTS_PENDING'); ?></span>
                <?php endif; ?>
                <span class="yap-dot" aria-hidden="true">·</span>
                <a class="yap-time" href="#comment-<?php echo $c->id; ?>" data-yap-permalink="<?php echo $e($c->permalink); ?>" title="<?php echo $e($c->fullDate); ?>">
                    <time datetime="<?php echo $e($c->iso); ?>" data-yap-time<?php echo $md ? ' itemprop="dateCreated"' : ''; ?>><?php echo $e($c->date); ?></time>
                </a>
                <?php if ($c->isEdited && !$c->isDeleted) : ?>
                    <span class="yap-edited"><?php echo Text::_('COM_YAPCOMMENTS_EDITED'); ?></span>
                <?php endif; ?>
            </header>

            <?php if ($c->collapsed) : ?>
                <button type="button" class="yap-reveal" data-yap-reveal><?php echo Icons::svg('eye'); ?><?php echo Text::_('COM_YAPCOMMENTS_LOW_SCORE'); ?></button>
            <?php endif; ?>

            <div class="yap-body" data-yap-body<?php echo $md ? ' itemprop="text"' : ''; ?>>
                <?php if ($c->isDeleted) : ?>
                    <p class="yap-muted"><?php echo Text::_('COM_YAPCOMMENTS_DELETED_BODY'); ?></p>
                <?php else : ?>
                    <?php echo $c->html; ?>
                <?php endif; ?>
            </div>

            <?php if (!$c->isDeleted) : ?>
                <footer class="yap-actions">
                    <?php if ($ctx->votes && !$c->isPending) : ?>
                        <?php if ($md) : ?>
                            <meta itemprop="upvoteCount" content="<?php echo $c->likes; ?>">
                            <?php if ($ctx->dislikes) : ?><meta itemprop="downvoteCount" content="<?php echo $c->dislikes; ?>"><?php endif; ?>
                        <?php endif; ?>
                        <div class="yap-votes<?php echo $ctx->score ? ' yap-votes--score' : ''; ?>" data-yap-votes>
                            <button type="button" class="yap-vote yap-vote--up<?php echo $c->myVote === 1 ? ' is-active' : ''; ?>" data-yap-vote="1"
                                aria-pressed="<?php echo $c->myVote === 1 ? 'true' : 'false'; ?>" aria-label="<?php echo Text::_('COM_YAPCOMMENTS_LIKE'); ?>"
                                <?php echo $c->canVote ? '' : 'disabled'; ?>>
                                <?php echo Icons::svg('like'); ?>
                                <?php if (!$ctx->score) : ?><span class="yap-vote__count" data-yap-likes><?php echo $c->likes; ?></span><?php endif; ?>
                            </button>
                            <?php if ($ctx->score) : ?>
                                <span class="yap-vote__score<?php echo $c->score > 0 ? ' is-positive' : ($c->score < 0 ? ' is-negative' : ''); ?>" data-yap-score><?php echo $c->score; ?></span>
                            <?php endif; ?>
                            <?php if ($ctx->dislikes) : ?>
                                <button type="button" class="yap-vote yap-vote--down<?php echo $c->myVote === -1 ? ' is-active' : ''; ?>" data-yap-vote="-1"
                                    aria-pressed="<?php echo $c->myVote === -1 ? 'true' : 'false'; ?>" aria-label="<?php echo Text::_('COM_YAPCOMMENTS_DISLIKE'); ?>"
                                    <?php echo $c->canVote ? '' : 'disabled'; ?>>
                                    <?php echo Icons::svg('dislike'); ?>
                                    <?php if (!$ctx->score) : ?><span class="yap-vote__count" data-yap-dislikes><?php echo $c->dislikes; ?></span><?php endif; ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($c->canReply) : ?>
                        <button type="button" class="yap-action" data-yap-reply><?php echo Icons::svg('reply'); ?><span><?php echo Text::_('COM_YAPCOMMENTS_REPLY'); ?></span></button>
                    <?php endif; ?>

                    <button type="button" class="yap-action" data-yap-share><?php echo Icons::svg('link'); ?><span><?php echo Text::_('COM_YAPCOMMENTS_SHARE'); ?></span></button>

                    <?php if ($hasMenu) : ?>
                        <div class="yap-menu" data-yap-menu>
                            <button type="button" class="yap-action yap-menu__toggle" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo Text::_('COM_YAPCOMMENTS_MORE'); ?>"><?php echo Icons::svg('more'); ?></button>
                            <div class="yap-menu__list" role="menu" hidden>
                                <?php if ($c->canEdit) : ?>
                                    <button type="button" role="menuitem" data-yap-edit><?php echo Icons::svg('edit'); ?><?php echo Text::_('COM_YAPCOMMENTS_EDIT'); ?></button>
                                <?php endif; ?>
                                <?php if ($c->canModerate) : ?>
                                    <?php if ($c->isPending) : ?>
                                        <button type="button" role="menuitem" data-yap-mod="approve"><?php echo Icons::svg('check'); ?><?php echo Text::_('COM_YAPCOMMENTS_APPROVE'); ?></button>
                                    <?php elseif ($c->depth === 0) : ?>
                                        <button type="button" role="menuitem" data-yap-mod="<?php echo $c->isPinned ? 'unpin' : 'pin'; ?>"><?php echo Icons::svg('pin'); ?><?php echo Text::_($c->isPinned ? 'COM_YAPCOMMENTS_UNPIN' : 'COM_YAPCOMMENTS_PIN'); ?></button>
                                    <?php endif; ?>
                                    <?php if (!$c->isPending) : ?>
                                        <button type="button" role="menuitem" data-yap-mod="unapprove"><?php echo Icons::svg('eyeoff'); ?><?php echo Text::_('COM_YAPCOMMENTS_UNAPPROVE'); ?></button>
                                    <?php endif; ?>
                                    <button type="button" role="menuitem" data-yap-mod="spam"><?php echo Icons::svg('ban'); ?><?php echo Text::_('COM_YAPCOMMENTS_SPAM'); ?></button>
                                <?php endif; ?>
                                <?php if ($c->canDelete) : ?>
                                    <button type="button" role="menuitem" class="is-danger" data-yap-delete><?php echo Icons::svg('trash'); ?><?php echo Text::_('COM_YAPCOMMENTS_DELETE'); ?></button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </footer>
            <?php elseif ($c->canModerate) : ?>
                <footer class="yap-actions">
                    <button type="button" class="yap-action" data-yap-mod="delete"><?php echo Icons::svg('trash'); ?><span><?php echo Text::_('COM_YAPCOMMENTS_DELETE_THREAD'); ?></span></button>
                </footer>
            <?php endif; ?>

            <div class="yap-reply-slot" data-yap-reply-slot></div>
        </div>
    </div>

    <div class="yap-replies" data-yap-replies>
        <?php foreach ($c->children as $i => $child) : ?>
            <?php if ($i === $ctx->replyLimit) : ?>
                <button type="button" class="yap-show-replies" data-yap-show-replies data-count="<?php echo \count($c->children) - $ctx->replyLimit; ?>">
                    <?php echo Icons::svg('chevron'); ?>
                    <span><?php echo Text::plural('COM_YAPCOMMENTS_SHOW_N_REPLIES', \count($c->children) - $ctx->replyLimit); ?></span>
                </button>
            <?php endif; ?>
            <?php echo $ctx->thread->renderPresented($child); ?>
        <?php endforeach; ?>
    </div>
</article>
