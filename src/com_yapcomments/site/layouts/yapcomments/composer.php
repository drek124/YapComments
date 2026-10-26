<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * The post form. A single form is used for new comments and replies: the
 * script moves it under the comment being answered so captchas keep working.
 *
 * @var array $displayData {ctx, captcha, stamp}
 */

\defined('_JEXEC') or die;

use Drek124\Component\YapComments\Administrator\Helper\YapHelper;
use Drek124\Component\YapComments\Site\Helper\Icons;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$ctx    = $displayData['ctx'];
$params = $ctx->params;
$user   = $ctx->user;
$flags  = $ctx->flags;
$uid    = 'yap-form-' . (int) $ctx->item->id;
$max    = (int) $params->get('max_length', 5000);
$has    = static fn (int $flag): bool => ($flags & $flag) === $flag;

$tools = [];

if ($params->get('show_toolbar', 1) && $has(YapHelper::FLAG_MARKDOWN)) {
    $tools = [
        'bold'   => ['bold', 'COM_YAPCOMMENTS_TOOL_BOLD', 'Ctrl+B'],
        'italic' => ['italic', 'COM_YAPCOMMENTS_TOOL_ITALIC', 'Ctrl+I'],
        'strike' => ['strike', 'COM_YAPCOMMENTS_TOOL_STRIKE', ''],
        'quote'  => ['quote', 'COM_YAPCOMMENTS_TOOL_QUOTE', ''],
        'list'   => ['list', 'COM_YAPCOMMENTS_TOOL_LIST', ''],
        'olist'  => ['olist', 'COM_YAPCOMMENTS_TOOL_OLIST', ''],
    ];

    if ($has(YapHelper::FLAG_CODE)) {
        $tools['code'] = ['code', 'COM_YAPCOMMENTS_TOOL_CODE', ''];
    }

    if ($has(YapHelper::FLAG_LINKS)) {
        $tools['link'] = ['link', 'COM_YAPCOMMENTS_TOOL_LINK', 'Ctrl+K'];
    }

    if ($has(YapHelper::FLAG_IMAGES)) {
        $tools['image'] = ['image', 'COM_YAPCOMMENTS_TOOL_IMAGE', ''];
    }

    $tools['spoiler'] = ['eyeoff', 'COM_YAPCOMMENTS_TOOL_SPOILER', ''];
}

$avatar = YapHelper::avatar($user->guest ? '?' : (string) $user->name, $user->guest ? '' : (string) $user->email);
?>
<form class="yap-composer" id="<?php echo $uid; ?>" data-yap-composer novalidate autocomplete="on"<?php echo !empty($displayData['replyOnly']) ? ' data-yap-reply-only hidden' : ''; ?>>
    <div class="yap-composer__replying" data-yap-replying hidden>
        <?php echo Icons::svg('reply'); ?>
        <span data-yap-replying-text></span>
        <button type="button" class="yap-link-btn" data-yap-cancel-reply><?php echo Text::_('COM_YAPCOMMENTS_JS_CANCEL'); ?></button>
    </div>

    <div class="yap-composer__row">
        <?php if ($avatar['type'] !== 'none') : ?>
            <div class="yap-composer__avatar">
                <?php if ($avatar['type'] === 'image') : ?>
                    <img class="yap-avatar" src="<?php echo htmlspecialchars($avatar['url'], ENT_QUOTES); ?>" alt="" width="40" height="40" loading="lazy">
                <?php else : ?>
                    <span class="yap-avatar" style="--hue: <?php echo (int) $avatar['hue']; ?>" aria-hidden="true"><?php echo htmlspecialchars($avatar['initials'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="yap-composer__main">
            <?php if ($user->guest) : ?>
                <div class="yap-guest">
                    <label class="yap-field">
                        <span class="yap-field__label"><?php echo Text::_('COM_YAPCOMMENTS_FIELD_NAME'); ?> *</span>
                        <input type="text" name="name" required minlength="2" maxlength="60" autocomplete="name" class="yap-input">
                    </label>
                    <?php if ($params->get('guest_require_email', 0)) : ?>
                        <label class="yap-field">
                            <span class="yap-field__label">
                                <?php echo Text::_('COM_YAPCOMMENTS_FIELD_EMAIL'); ?> *
                                <small><?php echo Text::_('COM_YAPCOMMENTS_FIELD_EMAIL_PRIVATE'); ?></small>
                            </span>
                            <input type="email" name="email" required maxlength="150" autocomplete="email" class="yap-input">
                        </label>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="yap-editor" data-yap-editor>
                <div class="yap-editor__bar">
                    <?php if ($params->get('show_preview', 1)) : ?>
                        <div class="yap-tabs" role="tablist">
                            <button type="button" role="tab" class="yap-tab is-active" aria-selected="true" data-yap-tab="write"><?php echo Text::_('COM_YAPCOMMENTS_TAB_WRITE'); ?></button>
                            <button type="button" role="tab" class="yap-tab" aria-selected="false" data-yap-tab="preview"><?php echo Text::_('COM_YAPCOMMENTS_TAB_PREVIEW'); ?></button>
                        </div>
                    <?php endif; ?>
                    <?php if ($tools) : ?>
                        <div class="yap-tools" role="toolbar" aria-label="<?php echo Text::_('COM_YAPCOMMENTS_TOOLBAR'); ?>">
                            <?php foreach ($tools as $tool => [$icon, $label, $key]) : ?>
                                <button type="button" class="yap-tool" data-yap-md="<?php echo $tool; ?>" title="<?php echo htmlspecialchars(Text::_($label) . ($key ? ' (' . $key . ')' : ''), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo Text::_($label); ?>">
                                    <?php echo Icons::svg($icon); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <textarea name="body" class="yap-textarea" rows="3" required
                    minlength="<?php echo (int) $params->get('min_length', 3); ?>" maxlength="<?php echo $max; ?>"
                    placeholder="<?php echo Text::_('COM_YAPCOMMENTS_PLACEHOLDER'); ?>"
                    aria-label="<?php echo Text::_('COM_YAPCOMMENTS_PLACEHOLDER'); ?>"></textarea>
                <div class="yap-preview yap-body" data-yap-preview hidden></div>
            </div>

            <?php if ($params->get('honeypot', 1)) : ?>
                <div class="yap-hp" aria-hidden="true">
                    <label>Website <input type="text" name="yap_url" tabindex="-1" autocomplete="off" value=""></label>
                </div>
            <?php endif; ?>

            <?php if ($displayData['captcha'] !== '') : ?>
                <div class="yap-captcha"><?php echo $displayData['captcha']; ?></div>
            <?php endif; ?>

            <div class="yap-composer__footer">
                <span class="yap-composer__actions">
                    <?php if ($params->get('show_counter', 1)) : ?>
                        <span class="yap-counter" data-yap-counter data-max="<?php echo $max; ?>" aria-live="polite"></span>
                    <?php endif; ?>
                    <button type="submit" class="yap-btn yap-btn--primary" data-yap-submit>
                        <span><?php echo Text::_('COM_YAPCOMMENTS_SUBMIT'); ?></span>
                        <?php echo Icons::svg('send'); ?>
                    </button>
                </span>
            </div>
        </div>
    </div>

    <input type="hidden" name="parent_id" value="0">
    <input type="hidden" name="yap_ts" value="<?php echo htmlspecialchars($displayData['stamp'], ENT_QUOTES); ?>">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
