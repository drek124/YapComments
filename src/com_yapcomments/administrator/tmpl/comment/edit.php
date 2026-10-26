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

/** @var \Drek124\Component\YapComments\Administrator\View\Comment\HtmlView $this */

$wa = $this->getDocument()->getWebAssetManager();
$wa->getRegistry()->addExtensionRegistryFile('com_yapcomments');
$wa->useScript('keepalive')->useScript('form.validate')->useStyle('com_yapcomments.admin');
?>
<form action="<?php echo Route::_('index.php?option=com_yapcomments&layout=edit&id=' . (int) $this->item->id); ?>" method="post" name="adminForm" id="comment-form" class="form-validate">
    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-body">
                    <?php echo $this->form->renderFieldset('main'); ?>
                </div>
            </div>
            <?php if ($this->preview !== '') : ?>
                <div class="card mb-3">
                    <div class="card-header"><?php echo Text::_('COM_YAPCOMMENTS_PREVIEW'); ?></div>
                    <div class="card-body yap-admin-preview"><?php echo $this->preview; ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($this->item->item_url)) : ?>
                <a class="btn btn-outline-primary" href="<?php echo $this->escape($this->item->item_url); ?>#comment-<?php echo (int) $this->item->id; ?>" target="_blank" rel="noopener">
                    <span class="icon-external-link-alt" aria-hidden="true"></span> <?php echo Text::_('COM_YAPCOMMENTS_VIEW_ON_SITE'); ?>
                </a>
            <?php endif; ?>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <?php echo $this->form->renderFieldset('details'); ?>
                </div>
            </div>
        </div>
    </div>

    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
