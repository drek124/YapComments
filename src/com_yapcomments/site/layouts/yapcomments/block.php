<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * The whole comments section.
 * Override: templates/YOUR_TEMPLATE/html/layouts/com_yapcomments/yapcomments/block.php
 *
 * @var array $displayData {ctx, sort, page, composer, config}
 */

\defined('_JEXEC') or die;

use Drek124\Component\YapComments\Site\Helper\Icons;
use Joomla\CMS\Language\Text;

$ctx      = $displayData['ctx'];
$params   = $ctx->params;
$page     = $displayData['page'];
$sort     = $displayData['sort'];
$composer = $displayData['composer'];
$uid      = 'yap-' . (int) $ctx->item->id;
$heading  = trim((string) $params->get('heading', '')) ?: Text::_('COM_YAPCOMMENTS_HEADING');
$accent   = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $params->get('accent')) ? $params->get('accent') : '#6366f1';
$atTop    = $params->get('composer_position', 'top') === 'top';
$css      = trim(str_ireplace('</style', '', (string) $params->get('custom_css', '')));

$classes = [
    'yap',
    'yap--' . preg_replace('/[^a-z]/', '', $params->get('style', 'cards')),
    'yap--' . preg_replace('/[^a-z]/', '', $params->get('density', 'comfortable')),
    'yap--anim-' . preg_replace('/[^a-z]/', '', $params->get('animations', 'full')),
    $params->get('font', 'inherit') === 'system' ? 'yap--system-font' : '',
    $ctx->open ? '' : 'yap--locked',
];

$style = '--yap-accent:' . $accent . ';--yap-radius:' . (int) $params->get('radius', 14) . 'px';
?>
<section id="comments" class="<?php echo implode(' ', array_filter($classes)); ?>"
    data-theme="<?php echo htmlspecialchars($params->get('theme', 'auto'), ENT_QUOTES); ?>"
    data-yap="<?php echo htmlspecialchars(json_encode($displayData['config'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
    style="<?php echo $style; ?>"
    aria-labelledby="<?php echo $uid; ?>-title">
    <?php if ($css !== '') : ?>
        <style><?php echo $css; ?></style>
    <?php endif; ?>

    <header class="yap-head">
        <h2 class="yap-title" id="<?php echo $uid; ?>-title">
            <?php echo Icons::svg('chat', 'yap-icon yap-title__icon'); ?>
            <span><?php echo htmlspecialchars($heading, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="yap-count" data-yap-count aria-label="<?php echo Text::plural('COM_YAPCOMMENTS_N_COMMENTS', $page['count']); ?>"><?php echo (int) $page['count']; ?></span>
        </h2>

        <?php if ($params->get('allow_sort_switch', 1) && $page['total'] > 1) : ?>
            <div class="yap-sort" role="group" aria-label="<?php echo Text::_('COM_YAPCOMMENTS_SORT_LABEL'); ?>">
                <?php foreach (['newest', 'oldest', 'top'] as $option) : ?>
                    <?php if ($option === 'top' && !$ctx->votes) : continue; endif; ?>
                    <button type="button" class="yap-sort__btn<?php echo $option === $sort ? ' is-active' : ''; ?>" data-yap-sort="<?php echo $option; ?>" aria-pressed="<?php echo $option === $sort ? 'true' : 'false'; ?>">
                        <?php echo Text::_('COM_YAPCOMMENTS_SORT_' . strtoupper($option)); ?>
                    </button>
                <?php endforeach; ?>
                <span class="yap-sort__glider" aria-hidden="true"></span>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($atTop) : ?>
        <div class="yap-composer-slot" data-yap-home><?php echo $composer; ?></div>
    <?php endif; ?>

    <div class="yap-list" data-yap-list>
        <?php echo $page['html']; ?>
    </div>

    <div class="yap-empty" data-yap-empty<?php echo $page['html'] !== '' ? ' hidden' : ''; ?>>
        <div class="yap-empty__art" aria-hidden="true"><?php echo Icons::svg('sparkle', 'yap-icon'); ?></div>
        <p><?php echo Text::_($ctx->open ? 'COM_YAPCOMMENTS_EMPTY' : 'COM_YAPCOMMENTS_EMPTY_LOCKED'); ?></p>
    </div>

    <?php if ($page['hasMore']) : ?>
        <div class="yap-more-wrap">
            <a class="yap-btn yap-btn--ghost yap-more" data-yap-more href="<?php echo htmlspecialchars($page['next'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $params->get('crawlable_pagination', 1) ? '' : ' rel="nofollow"'; ?>>
                <?php echo Text::_('COM_YAPCOMMENTS_LOAD_MORE'); ?>
                <?php echo Icons::svg('chevron'); ?>
            </a>
        </div>
    <?php endif; ?>

    <?php if (!$atTop) : ?>
        <div class="yap-composer-slot" data-yap-home><?php echo $composer; ?></div>
    <?php endif; ?>

    <footer class="yap-watermark">
        <a href="https://github.com/drek124/YapComments" target="_blank" rel="noopener">
            <?php echo Icons::svg('chat'); ?><span>YapComments</span>
        </a>
    </footer>

    <div class="yap-toasts" data-yap-toasts role="status" aria-live="polite"></div>
</section>
