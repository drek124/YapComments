<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

/**
 * Shared constants and small utilities used by both the site and the administrator.
 */
abstract class YapHelper
{
    public const STATE_PUBLISHED = 1;
    public const STATE_PENDING   = 0;
    public const STATE_SPAM      = -1;
    public const STATE_TRASHED   = -2;

    /** The author was allowed to use Markdown formatting when posting. */
    public const FLAG_MARKDOWN = 1;
    /** The author was allowed to post clickable links. */
    public const FLAG_LINKS = 2;
    /** The author was allowed to embed images. */
    public const FLAG_IMAGES = 4;
    /** The author was allowed to post code blocks. */
    public const FLAG_CODE = 8;
    /** The comment was removed by its author but kept to preserve the thread. */
    public const FLAG_DELETED = 16;

    /**
     * Fallback values for every option, so the component behaves sensibly
     * before the Options screen has ever been saved.
     */
    public const DEFAULTS = [
        'enabled'              => 1,
        'order'                => 'newest',
        'allow_sort_switch'    => 1,
        'per_page'             => 10,
        'max_depth'            => 4,
        'auto_close_days'      => 0,
        'edit_window'          => 15,
        'date_format'          => 'relative',
        'guest_require_email'  => 0,
        'guest_protect_names'  => 1,
        'heading'              => '',
        'theme'                => 'auto',
        'style'                => 'cards',
        'density'              => 'comfortable',
        'accent'               => '#6366f1',
        'radius'               => 14,
        'font'                 => 'inherit',
        'animations'           => 'full',
        'avatars'              => 'initials',
        'gravatar_default'     => 'identicon',
        'composer_position'    => 'top',
        'show_toolbar'         => 1,
        'show_preview'         => 1,
        'show_counter'         => 1,
        'custom_css'           => '',
        'votes_enabled'        => 1,
        'dislikes_enabled'     => 1,
        'vote_display'         => 'separate',
        'self_vote'            => 0,
        'collapse_threshold'   => -5,
        'min_length'           => 3,
        'max_length'           => 5000,
        'max_links'            => 3,
        'link_rel'             => 'ugc nofollow',
        'internal_follow'      => 1,
        'link_blank'           => 1,
        'domain_mode'          => 'none',
        'domain_list'          => '',
        'badwords'             => '',
        'badwords_action'      => 'censor',
        'captcha'              => '',
        'captcha_guests_only'  => 1,
        'honeypot'             => 1,
        'min_submit_time'      => 3,
        'flood_interval'       => 30,
        'hourly_limit'         => 20,
        'duplicate_check'      => 1,
        'hold_links'           => 0,
        'ip_mode'              => 'anonymize',
        'notify_admins'        => 0,
        'notify_emails'        => '',
        'notify_mode'          => 'pending',
        'notify_reply'         => 1,
        'microdata'            => 1,
        'crawlable_pagination' => 1,
    ];

    private static ?Registry $params = null;

    /**
     * Component options merged over the defaults.
     */
    public static function params(): Registry
    {
        if (self::$params === null) {
            self::$params = new Registry(self::DEFAULTS);
            self::$params->merge(ComponentHelper::getParams('com_yapcomments'));
        }

        return self::$params;
    }

    /**
     * Check one of the component's custom permissions, e.g. can('post').
     */
    public static function can(string $action, ?User $user = null): bool
    {
        $user ??= Factory::getApplication()->getIdentity() ?? new User();

        return (bool) $user->authorise('yapcomments.' . $action, 'com_yapcomments');
    }

    /**
     * Formatting abilities the user has right now, stored with each comment
     * so it keeps rendering the way its author was allowed to write it.
     */
    public static function flagsFor(?User $user = null): int
    {
        $flags = 0;

        if (self::can('markdown', $user)) {
            $flags |= self::FLAG_MARKDOWN;

            if (self::can('code', $user)) {
                $flags |= self::FLAG_CODE;
            }

            if (self::can('images', $user)) {
                $flags |= self::FLAG_IMAGES;
            }
        }

        if (self::can('links', $user)) {
            $flags |= self::FLAG_LINKS;
        }

        return $flags;
    }

    /**
     * The visitor's address as seen by the web server. Proxy headers are
     * deliberately ignored because they are trivially spoofed.
     */
    public static function clientIp(): string
    {
        $ip = (string) Factory::getApplication()->getInput()->server->getString('REMOTE_ADDR', '');

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * Transform an address according to the privacy setting before it is stored.
     */
    public static function storableIp(string $ip, ?string $mode = null): string
    {
        $mode ??= self::params()->get('ip_mode', 'anonymize');

        if ($ip === '' || $mode === 'none') {
            return '';
        }

        if ($mode === 'hash') {
            return hash_hmac('sha256', $ip, self::secret());
        }

        if ($mode === 'anonymize') {
            $packed = @inet_pton($ip);

            if ($packed === false) {
                return '';
            }

            // Zero the last octet for IPv4 and the last 80 bits for IPv6.
            $keep   = \strlen($packed) === 4 ? 3 : 6;
            $packed = substr($packed, 0, $keep) . str_repeat("\0", \strlen($packed) - $keep);

            return (string) inet_ntop($packed);
        }

        return $ip;
    }

    /**
     * A stable identifier for a voter. Guests are identified by a keyed hash
     * of their address and browser so the raw values are never stored.
     */
    public static function voterKey(?User $user = null): string
    {
        $user ??= Factory::getApplication()->getIdentity() ?? new User();

        if (!$user->guest) {
            return 'u:' . (int) $user->id;
        }

        $ua = (string) Factory::getApplication()->getInput()->server->getString('HTTP_USER_AGENT', '');

        return 'g:' . substr(hash_hmac('sha256', self::clientIp() . '|' . $ua, self::secret()), 0, 60);
    }

    /**
     * Site-specific secret used for signing and hashing.
     */
    public static function secret(): string
    {
        return 'yapcomments|' . (string) Factory::getApplication()->get('secret');
    }

    /**
     * Rough count of URLs in raw comment text.
     */
    public static function countLinks(string $text): int
    {
        return (int) preg_match_all('#\bhttps?://#i', $text);
    }

    /**
     * Up to two initials for the avatar placeholder.
     */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $first = mb_substr($parts[0], 0, 1);
        $last  = \count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first . $last);
    }

    /**
     * A deterministic hue for a name, so each author keeps the same colour.
     */
    public static function hue(string $seed): int
    {
        return hexdec(substr(md5(mb_strtolower($seed)), 0, 4)) % 360;
    }

    /**
     * Avatar description for the templates.
     *
     * @return  array{type: string, url?: string, initials?: string, hue?: int}
     */
    public static function avatar(string $name, string $email): array
    {
        $mode = self::params()->get('avatars', 'initials');

        if ($mode === 'none') {
            return ['type' => 'none'];
        }

        if ($mode === 'gravatar' && $email !== '') {
            $hash = hash('sha256', strtolower(trim($email)));
            $d    = rawurlencode((string) self::params()->get('gravatar_default', 'identicon'));

            return ['type' => 'image', 'url' => 'https://www.gravatar.com/avatar/' . $hash . '?s=96&d=' . $d];
        }

        return ['type' => 'initials', 'initials' => self::initials($name), 'hue' => self::hue($name)];
    }

    /**
     * Human readable list of the states, used by filters and badges.
     */
    public static function stateKey(int $state): string
    {
        return match ($state) {
            self::STATE_PUBLISHED => 'PUBLISHED',
            self::STATE_PENDING   => 'PENDING',
            self::STATE_SPAM      => 'SPAM',
            self::STATE_TRASHED   => 'TRASHED',
            default               => 'UNKNOWN',
        };
    }
}
