<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Shown instead of the post form (locked thread, guest, no permission).
 *
 * @var array $displayData {ctx, type, login?}
 */

\defined('_JEXEC') or die;

use Drek124\Component\YapComments\Site\Helper\Icons;
use Joomla\CMS\Language\Text;

$type = $displayData['type'];
?>
<div class="yap-notice yap-notice--<?php echo $type; ?>">
    <?php echo Icons::svg($type === 'login' ? 'chat' : 'lock'); ?>
    <p><?php echo Text::_('COM_YAPCOMMENTS_NOTICE_' . strtoupper($type)); ?></p>
    <?php if ($type === 'login') : ?>
        <a class="yap-btn yap-btn--primary" href="<?php echo htmlspecialchars($displayData['login'], ENT_QUOTES, 'UTF-8'); ?>" rel="nofollow"><?php echo Text::_('COM_YAPCOMMENTS_LOGIN'); ?></a>
    <?php endif; ?>
</div>
