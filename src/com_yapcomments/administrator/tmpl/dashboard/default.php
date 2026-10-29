<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/** @var \Drek124\Component\YapComments\Administrator\View\Dashboard\HtmlView $this */

$stats   = $this->stats;
$max     = max(1, ...array_values($this->activity));
$return  = base64_encode(Uri::getInstance()->toString());
$canEdit = $this->getCurrentUser()->authorise('core.edit.state', 'com_yapcomments');
$list    = 'index.php?option=com_yapcomments&view=comments';

$cards = [
    ['key' => 'total', 'icon' => 'comments', 'url' => $list . '&filter[state]=*'],
    ['key' => 'published', 'icon' => 'check', 'url' => $list . '&filter[state]=1'],
    ['key' => 'pending', 'icon' => 'clock', 'url' => $list . '&filter[state]=0'],
    ['key' => 'spam', 'icon' => 'warning', 'url' => $list . '&filter[state]=-1'],
    ['key' => 'today', 'icon' => 'calendar', 'url' => $list],
    ['key' => 'likes', 'icon' => 'thumbs-up', 'url' => $list . '&list[fullordering]=a.likes DESC'],
];

$excerpt = static fn (string $text): string => htmlspecialchars(mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $text)), 0, 140, '…'), ENT_QUOTES, 'UTF-8');
?>
<div class="yap-admin">
    <header class="yap-admin__hero">
        <div>
            <h2 class="yap-admin__title">YapComments</h2>
            <p class="yap-admin__subtitle"><?php echo Text::_('COM_YAPCOMMENTS_DASHBOARD_SUBTITLE'); ?></p>
        </div>
        <a class="yap-admin__pill" href="<?php echo Route::_($list . '&filter[state]=0'); ?>">
            <span class="icon-clock" aria-hidden="true"></span>
            <?php echo Text::plural('COM_YAPCOMMENTS_DASHBOARD_N_PENDING', $stats['pending']); ?>
        </a>
    </header>

    <div class="yap-admin__cards">
        <?php foreach ($cards as $i => $card) : ?>
            <a class="yap-admin__card yap-admin__card--<?php echo $card['key']; ?>" style="--i: <?php echo $i; ?>" href="<?php echo Route::_($card['url']); ?>">
                <span class="yap-admin__card-icon icon-<?php echo $card['icon']; ?>" aria-hidden="true"></span>
                <span class="yap-admin__card-value" data-count="<?php echo (int) $stats[$card['key']]; ?>"><?php echo (int) $stats[$card['key']]; ?></span>
                <span class="yap-admin__card-label"><?php echo Text::_('COM_YAPCOMMENTS_STAT_' . strtoupper($card['key'])); ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="yap-admin__grid">
        <section class="yap-admin__panel yap-admin__panel--wide">
            <h3><?php echo Text::_('COM_YAPCOMMENTS_DASHBOARD_ACTIVITY'); ?></h3>
            <div class="yap-admin__chart" role="img" aria-label="<?php echo Text::_('COM_YAPCOMMENTS_DASHBOARD_ACTIVITY'); ?>">
                <?php $i = 0; foreach ($this->activity as $day => $count) : ?>
                    <div class="yap-admin__bar<?php echo $count ? "" : " is-zero"; ?>" style="--h: <?php echo round($count / $max * 100, 1); ?>%; --i: <?php echo $i++; ?>" title="<?php echo HTMLHelper::_('date', $day, Text::_('DATE_FORMAT_LC4'), 'UTC') . ': ' . $count; ?>">
                        <span><?php echo $count ?: ''; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="yap-admin__panel">
            <h3><?php echo Text::_('COM_YAPCOMMENTS_DASHBOARD_QUEUE'); ?></h3>
            <?php if (!$this->pending) : ?>
                <p class="yap-admin__empty"><span class="icon-check" aria-hidden="true"></span> <?php echo Text::_('COM_YAPCOMMENTS_DASHBOARD_QUEUE_EMPTY'); ?></p>
            <?php endif; ?>
            <ul class="yap-admin__list">
                <?php foreach ($this->pending as $row) : ?>
                    <li>
                        <div class="yap-admin__meta">
                            <strong><?php echo htmlspecialchars($row->user_name ?: $row->name, ENT_QUOTES, 'UTF-8'); ?></strong>
                            · <?php echo HTMLHelper::_('date.relative', $row->created); ?>
                            · <a href="<?php echo htmlspecialchars($row->item_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($row->item_title, ENT_QUOTES, 'UTF-8'); ?></a>
                        </div>
                        <p><?php echo $excerpt($row->body); ?></p>
                        <?php if ($canEdit) : ?>
                            <div class="yap-admin__actions">
                                <?php foreach (['publish' => ['btn-success', 'COM_YAPCOMMENTS_TOOLBAR_APPROVE'], 'spam' => ['btn-warning', 'COM_YAPCOMMENTS_TOOLBAR_SPAM'], 'trash' => ['btn-outline-danger', 'JTRASH']] as $task => [$class, $label]) : ?>
                                    <form action="<?php echo Route::_('index.php?option=com_yapcomments'); ?>" method="post">
                                        <input type="hidden" name="task" value="comments.<?php echo $task; ?>">
                                        <input type="hidden" name="cid[]" value="<?php echo (int) $row->id; ?>">
                                        <input type="hidden" name="return" value="<?php echo $return; ?>">
                                        <?php echo HTMLHelper::_('form.token'); ?>
                                        <button type="submit" class="btn btn-sm <?php echo $class; ?>"><?php echo Text::_($label); ?></button>
                                    </form>
                                <?php endforeach; ?>
                                <a class="btn btn-sm btn-link" href="<?php echo Route::_('index.php?option=com_yapcomments&task=comment.edit&id=' . (int) $row->id); ?>"><?php echo Text::_('JACTION_EDIT'); ?></a>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="yap-admin__panel">
            <h3><?php echo Text::_('COM_YAPCOMMENTS_DASHBOARD_TOP'); ?></h3>
            <ol class="yap-admin__top">
                <?php foreach ($this->topItems as $item) : ?>
                    <li>
                        <a href="<?php echo Route::_($list . '&filter[item_id]=' . (int) $item->item_id . '&filter[state]=*'); ?>"><?php echo htmlspecialchars($item->item_title ?: '#' . $item->item_id, ENT_QUOTES, 'UTF-8'); ?></a>
                        <span class="badge bg-primary"><?php echo (int) $item->total; ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php if (!$this->topItems) : ?>
                <p class="yap-admin__empty"><?php echo Text::_('COM_YAPCOMMENTS_DASHBOARD_NO_DATA'); ?></p>
            <?php endif; ?>
        </section>

        <section class="yap-admin__panel">
            <h3><?php echo Text::_('COM_YAPCOMMENTS_DASHBOARD_RECENT'); ?></h3>
            <ul class="yap-admin__list">
                <?php foreach ($this->recent as $row) : ?>
                    <li>
                        <div class="yap-admin__meta">
                            <strong><?php echo htmlspecialchars($row->user_name ?: $row->name, ENT_QUOTES, 'UTF-8'); ?></strong>
                            · <?php echo HTMLHelper::_('date.relative', $row->created); ?>
                            · <span class="text-success">▲ <?php echo (int) $row->likes; ?></span>
                            <span class="text-danger">▼ <?php echo (int) $row->dislikes; ?></span>
                        </div>
                        <p><a href="<?php echo Route::_('index.php?option=com_yapcomments&task=comment.edit&id=' . (int) $row->id); ?>"><?php echo $excerpt($row->body); ?></a></p>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!$this->recent) : ?>
                <p class="yap-admin__empty"><?php echo Text::_('COM_YAPCOMMENTS_DASHBOARD_NO_DATA'); ?></p>
            <?php endif; ?>
        </section>
    </div>

    <footer class="yap-admin__footer">
        YapComments 1.0.3 · <?php echo Text::_('COM_YAPCOMMENTS_BY'); ?> <a href="https://github.com/drek124/" target="_blank" rel="noopener">drek124</a>
    </footer>
</div>
