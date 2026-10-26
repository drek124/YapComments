<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Site\Helper;

\defined('_JEXEC') or die;

/**
 * Inline SVG icons (stroke style, 24px grid) so no icon font is needed.
 */
abstract class Icons
{
    private const PATHS = [
        'like'     => '<path d="M7 10v11"/><path d="M15 5.9 14 10h5.8a2 2 0 0 1 2 2.3l-1.4 7A2 2 0 0 1 18.4 21H7V10l4.5-7.4a1.9 1.9 0 0 1 3.5 1.3z"/>',
        'dislike'  => '<path d="M17 14V3"/><path d="M9 18.1 10 14H4.2a2 2 0 0 1-2-2.3l1.4-7A2 2 0 0 1 5.6 3H17v11l-4.5 7.4a1.9 1.9 0 0 1-3.5-1.3z"/>',
        'reply'    => '<path d="M9 17 4 12l5-5"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>',
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'trash'    => '<path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'link'     => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'pin'      => '<path d="M12 17v5"/><path d="M9 10.8V4h6v6.8l3 3.2v2H6v-2z"/>',
        'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'check'    => '<path d="M20 6 9 17l-5-5"/>',
        'ban'      => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
        'more'     => '<circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'lock'     => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'chat'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'send'     => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
        'bold'     => '<path d="M6 4h8a4 4 0 0 1 0 8H6z"/><path d="M6 12h9a4 4 0 0 1 0 8H6z"/>',
        'italic'   => '<path d="M19 4h-9M14 20H5M15 4 9 20"/>',
        'strike'   => '<path d="M16 4H9a3 3 0 0 0-2.8 4"/><path d="M14 12a4 4 0 0 1 0 8H6"/><path d="M4 12h16"/>',
        'code'     => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
        'quote'    => '<path d="M3 21c3 0 7-1 7-8V5H3v7h4c0 3-1 5-4 5z"/><path d="M14 21c3 0 7-1 7-8V5h-7v7h4c0 3-1 5-4 5z"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
        'olist'    => '<path d="M10 6h11M10 12h11M10 18h11"/><path d="M4 6h1v4M4 10h2M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>',
        'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>',
        'eye'      => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eyeoff'   => '<path d="M9.9 4.2A10 10 0 0 1 12 4c7 0 10 8 10 8a13 13 0 0 1-1.7 2.7"/><path d="M6.6 6.6A13.5 13.5 0 0 0 2 12s3 8 10 8a9.7 9.7 0 0 0 5.4-1.6"/><path d="m2 2 20 20"/>',
        'chevron'  => '<path d="m6 9 6 6 6-6"/>',
        'sparkle'  => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M6 18l2.5-2.5M15.5 8.5 18 6"/>',
    ];

    public static function svg(string $name, string $class = 'yap-icon'): string
    {
        $paths = self::PATHS[$name] ?? '';

        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }
}
