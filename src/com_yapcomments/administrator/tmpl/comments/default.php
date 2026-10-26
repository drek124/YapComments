<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Drek124\Component\YapComments\Administrator\Helper\YapHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/** @var \Drek124\Component\YapComments\Administrator\View\Comments\HtmlView $this */

$wa = $this->getDocument()->getWebAssetManager();
$wa->getRegistry()->addExtensionRegistryFile('com_yapcomments');
$wa->useScript('table.columns')->useScript('multiselect')->useStyle('com_yapcomments.admin');

$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
$user      = $this->getCurrentUser();
$canEdit   = $user->authorise('core.edit', 'com_yapcomments');
$canState  = $user->authorise('core.edit.state', 'com_yapcomments');

$badges = [
    YapHelper::STATE_PUBLISHED => 'success',
    YapHelper::STATE_PENDING   => 'warning',
    YapHelper::STATE_SPAM      => 'danger',
    YapHelper::STATE_TRASHED   => 'secondary',
];
?>
<form action="<?php echo Route::_('index.php?option=com_yapcomments&view=comments'); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                        <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
                    </div>
                <?php else : ?>
                    <table class="table yap-admin-table" id="commentList">
                        <caption class="visually-hidden">
                            <?php echo Text::_('COM_YAPCOMMENTS_TABLE_CAPTION'); ?>,
                            <span id="orderedBy"><?php echo Text::_('JGLOBAL_SORTED_BY'); ?> </span>,
                            <span id="filteredBy"><?php echo Text::_('JGLOBAL_FILTERED_BY'); ?></span>
                        </caption>
                        <thead>
                            <tr>
                                <td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                                <th scope="col" class="w-1 text-center">
                                    <?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
                                </th>
                                <th scope="col"><?php echo Text::_('COM_YAPCOMMENTS_HEADING_COMMENT'); ?></th>
                                <th scope="col" class="w-15 d-none d-md-table-cell">
                                    <?php echo HTMLHelper::_('searchtools.sort', 'COM_YAPCOMMENTS_HEADING_ITEM', 'a.item_title', $listDirn, $listOrder); ?>
                                </th>
                                <th scope="col" class="w-10 d-none d-md-table-cell text-center">
                                    <?php echo HTMLHelper::_('searchtools.sort', 'COM_YAPCOMMENTS_HEADING_VOTES', 'a.likes', $listDirn, $listOrder); ?>
                                </th>
                                <th scope="col" class="w-10 d-none d-md-table-cell">
                                    <?php echo HTMLHelper::_('searchtools.sort', 'JDATE', 'a.created', $listDirn, $listOrder); ?>
                                </th>
                                <th scope="col" class="w-5 d-none d-md-table-cell">
                                    <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->items as $i => $item) : ?>
                                <?php $author = $item->user_name ?: $item->name; ?>
                                <tr class="row<?php echo $i % 2; ?>">
                                    <td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', $author); ?></td>
                                    <td class="text-center">
                                        <?php if ($canState && \in_array((int) $item->state, [0, 1], true)) : ?>
                                            <?php echo HTMLHelper::_('jgrid.published', (int) $item->state, $i, 'comments.', true, 'cb'); ?>
                                        <?php else : ?>
                                            <span class="badge bg-<?php echo $badges[(int) $item->state] ?? 'secondary'; ?>"><?php echo Text::_('COM_YAPCOMMENTS_STATE_' . YapHelper::stateKey((int) $item->state)); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <th scope="row">
                                        <div class="yap-admin-table__author">
                                            <?php if ($item->pinned) : ?>
                                                <span class="badge bg-info"><span class="icon-pin" aria-hidden="true"></span> <?php echo Text::_('COM_YAPCOMMENTS_PINNED'); ?></span>
                                            <?php endif; ?>
                                            <?php if ($item->checked_out) : ?>
                                                <?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->editor, $item->checked_out_time, 'comments.', $canEdit); ?>
                                            <?php endif; ?>
                                            <strong><?php echo $this->escape($author); ?></strong>
                                            <?php if (!$item->user_id) : ?>
                                                <span class="badge bg-light text-dark"><?php echo Text::_('COM_YAPCOMMENTS_GUEST'); ?></span>
                                            <?php endif; ?>
                                            <?php if ($item->email) : ?>
                                                <small class="text-muted"><?php echo $this->escape($item->email); ?></small>
                                            <?php endif; ?>
                                            <?php if ($item->parent_id) : ?>
                                                <small class="text-muted">↪ #<?php echo (int) $item->parent_id; ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($canEdit) : ?>
                                            <a class="yap-admin-table__body" href="<?php echo Route::_('index.php?option=com_yapcomments&task=comment.edit&id=' . (int) $item->id); ?>">
                                                <?php echo $this->escape(mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $item->body)), 0, 220, '…')); ?>
                                            </a>
                                        <?php else : ?>
                                            <span class="yap-admin-table__body"><?php echo $this->escape(mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $item->body)), 0, 220, '…')); ?></span>
                                        <?php endif; ?>
                                        <?php if ($item->ip) : ?>
                                            <small class="text-muted d-block">IP: <?php echo $this->escape(strlen($item->ip) > 40 ? substr($item->ip, 0, 12) . '…' : $item->ip); ?></small>
                                        <?php endif; ?>
                                    </th>
                                    <td class="d-none d-md-table-cell">
                                        <?php if ($item->item_url) : ?>
                                            <a href="<?php echo $this->escape($item->item_url); ?>#comment-<?php echo (int) $item->id; ?>" target="_blank" rel="noopener"><?php echo $this->escape($item->item_title ?: '#' . $item->item_id); ?></a>
                                        <?php else : ?>
                                            <?php echo $this->escape($item->item_title ?: '#' . $item->item_id); ?>
                                        <?php endif; ?>
                                        <small class="d-block text-muted"><?php echo $this->escape($item->context); ?></small>
                                    </td>
                                    <td class="d-none d-md-table-cell text-center text-nowrap">
                                        <span class="text-success">▲ <?php echo (int) $item->likes; ?></span>
                                        <span class="text-danger ms-2">▼ <?php echo (int) $item->dislikes; ?></span>
                                    </td>
                                    <td class="d-none d-md-table-cell text-nowrap">
                                        <?php echo HTMLHelper::_('date', $item->created, Text::_('DATE_FORMAT_LC5')); ?>
                                    </td>
                                    <td class="d-none d-md-table-cell"><?php echo (int) $item->id; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php echo $this->pagination->getListFooter(); ?>
                <?php endif; ?>

                <input type="hidden" name="task" value="">
                <input type="hidden" name="boxchecked" value="0">
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>
