<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Site\Helper;

\defined('_JEXEC') or die;

use Drek124\Component\YapComments\Administrator\Helper\YapHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Registry\Registry;

/**
 * Plain-text e-mail notifications for moderators and repliers.
 */
final class Notifier
{
    public function __construct(private Registry $params)
    {
    }

    public function commentCreated(object $comment, ?object $parent): void
    {
        $pending = (int) $comment->state === YapHelper::STATE_PENDING;
        $link    = $comment->item_url . '#comment-' . (int) $comment->id;
        $excerpt = mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $comment->body)), 0, 500, '…');
        $site    = (string) Factory::getApplication()->get('sitename');

        if ($this->params->get('notify_admins', 0) && ($pending || $this->params->get('notify_mode', 'pending') === 'all')) {
            $emails = array_filter(
                array_map('trim', preg_split('/[\s,;]+/', (string) $this->params->get('notify_emails', ''))),
                static fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
            );

            if ($emails) {
                $admin = Uri::root() . 'administrator/index.php?option=com_yapcomments&task=comment.edit&id=' . (int) $comment->id;

                $this->send(
                    $emails,
                    Text::sprintf($pending ? 'COM_YAPCOMMENTS_MAIL_PENDING_SUBJECT' : 'COM_YAPCOMMENTS_MAIL_NEW_SUBJECT', $site, $comment->item_title),
                    Text::sprintf('COM_YAPCOMMENTS_MAIL_NEW_BODY', $comment->name, $comment->item_title, $excerpt, $link, $admin)
                );
            }
        }

        if ($parent && !$pending && $this->params->get('notify_reply', 1)) {
            $to = $this->recipient($parent);

            if ($to && strcasecmp($to, (string) $comment->email) !== 0) {
                $this->send(
                    [$to],
                    Text::sprintf('COM_YAPCOMMENTS_MAIL_REPLY_SUBJECT', $comment->name, $site),
                    Text::sprintf('COM_YAPCOMMENTS_MAIL_REPLY_BODY', $comment->name, $comment->item_title, $excerpt, $link)
                );
            }
        }
    }

    private function recipient(object $parent): string
    {
        if ((int) $parent->user_id) {
            $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $parent->user_id);

            return $user->block ? '' : (string) $user->email;
        }

        return filter_var($parent->email, FILTER_VALIDATE_EMAIL) ? (string) $parent->email : '';
    }

    private function send(array $to, string $subject, string $body): void
    {
        $app    = Factory::getApplication();
        $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();

        $mailer->setSender([$app->get('mailfrom'), $app->get('fromname')]);

        foreach ($to as $address) {
            $mailer->addRecipient($address);
        }

        $mailer->setSubject($subject);
        $mailer->setBody($body);
        $mailer->isHtml(false);
        $mailer->send();
    }
}
