<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

/**
 * A small, safe Markdown renderer for user comments.
 *
 * Raw HTML is never passed through: all text is escaped and only the markup
 * produced here reaches the page. Every feature is gated by the flags the
 * author held when posting (see YapHelper::FLAG_*).
 *
 * Supported: paragraphs, line breaks, **bold**, *italic*, ~~strike~~,
 * ||spoiler||, `inline code`, fenced code blocks, > quotes, lists, # headings
 * (rendered as bold lines so they never disturb the page outline), ---,
 * [links](https://…), bare URLs and ![images](https://…).
 */
final class MarkdownRenderer
{
    private const TOKEN = "\x1A";

    private const MAX_QUOTE_DEPTH = 3;

    /** @var string[] */
    private array $tokens = [];

    private int $flags = 0;

    private string $siteHost;

    /** @var string[] */
    private array $domains;

    public function __construct(private Registry $params)
    {
        $this->siteHost = strtolower((string) Uri::getInstance()->getHost());
        $this->domains  = array_values(array_filter(array_map(
            static fn ($d) => strtolower(trim(preg_replace('#^https?://#i', '', $d), " \t./")),
            preg_split('/[\s,]+/', (string) $params->get('domain_list', '')) ?: []
        )));
    }

    /**
     * Render comment text to safe HTML.
     */
    public function render(string $text, int $flags): string
    {
        $this->flags  = $flags;
        $this->tokens = [];
        $text         = self::normalize($text);

        if ($text === '') {
            return '';
        }

        $html = $this->has(YapHelper::FLAG_MARKDOWN) ? $this->blocks($text, 0) : $this->plain($text);

        return $this->restore($html);
    }

    /**
     * Strip control characters and broken UTF-8, unify line endings.
     */
    public static function normalize(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? '';

        return trim($text);
    }

    /**
     * Plain-text rendering for authors without Markdown permission.
     */
    private function plain(string $text): string
    {
        $out = [];

        foreach (preg_split('/\n{2,}/', $text) as $para) {
            $para = $this->has(YapHelper::FLAG_LINKS) ? $this->autolink($para) : $para;
            $out[] = '<p>' . nl2br($this->escape($para), false) . '</p>';
        }

        return implode("\n", $out);
    }

    /**
     * Block-level parsing.
     */
    private function blocks(string $text, int $depth): string
    {
        if ($this->has(YapHelper::FLAG_CODE)) {
            $text = preg_replace_callback(
                '/^```[ \t]*([\w#+.-]{0,20})[ \t]*\n(.*?)\n?```[ \t]*$/ms',
                function (array $m): string {
                    $lang = $m[1] !== '' ? ' class="language-' . $this->escape(strtolower($m[1])) . '"' : '';

                    return "\n\n" . $this->token('<pre class="yap-code"><code' . $lang . '>' . $this->escape($m[2]) . '</code></pre>') . "\n\n";
                },
                $text
            ) ?? $text;
        }

        $lines = explode("\n", $text);
        $count = \count($lines);
        $out   = [];
        $para  = [];

        $flush = function () use (&$para, &$out): void {
            if ($para) {
                $out[] = '<p>' . implode("<br>\n", array_map([$this, 'inline'], $para)) . '</p>';
                $para  = [];
            }
        };

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $flush();
                continue;
            }

            // A fenced code block placeholder on its own line.
            if (preg_match('/^' . self::TOKEN . '\d+' . self::TOKEN . '$/', trim($line))) {
                $flush();
                $out[] = trim($line);
                continue;
            }

            if (preg_match('/^ {0,3}(?:-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
                $flush();
                $out[] = '<hr>';
                continue;
            }

            if (preg_match('/^ {0,3}#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $flush();
                $out[] = '<p class="yap-heading"><strong>' . $this->inline($m[1]) . '</strong></p>';
                continue;
            }

            if (preg_match('/^ {0,3}>\s?(.*)$/', $line)) {
                $flush();
                $quote = [];

                while ($i < $count && preg_match('/^ {0,3}>\s?(.*)$/', $lines[$i], $m)) {
                    $quote[] = $m[1];
                    $i++;
                }

                $i--;
                $inner = implode("\n", $quote);
                $out[] = '<blockquote>' . ($depth < self::MAX_QUOTE_DEPTH ? $this->blocks($inner, $depth + 1) : '<p>' . $this->inline($inner) . '</p>') . '</blockquote>';
                continue;
            }

            if (preg_match('/^ {0,3}[-*+]\s+\S/', $line)) {
                $flush();
                $items = [];

                while ($i < $count && preg_match('/^ {0,3}[-*+]\s+(.*)$/', $lines[$i], $m)) {
                    $items[] = '<li>' . $this->inline($m[1]) . '</li>';
                    $i++;
                }

                $i--;
                $out[] = '<ul>' . implode('', $items) . '</ul>';
                continue;
            }

            if (preg_match('/^ {0,3}(\d{1,9})[.)]\s+\S/', $line, $start)) {
                $flush();
                $items = [];

                while ($i < $count && preg_match('/^ {0,3}\d{1,9}[.)]\s+(.*)$/', $lines[$i], $m)) {
                    $items[] = '<li>' . $this->inline($m[1]) . '</li>';
                    $i++;
                }

                $i--;
                $first = (int) $start[1];
                $out[] = '<ol' . ($first !== 1 ? ' start="' . $first . '"' : '') . '>' . implode('', $items) . '</ol>';
                continue;
            }

            $para[] = $line;
        }

        $flush();

        return implode("\n", $out);
    }

    /**
     * Inline parsing. Constructs that produce markup are swapped for tokens
     * first, then the remaining text is escaped and emphasis applied.
     */
    private function inline(string $text): string
    {
        // Backslash escapes.
        $text = preg_replace_callback('/\\\\([\\\\`*_{}\[\]()#+\-.!|~>])/', fn ($m) => $this->token($this->escape($m[1])), $text) ?? $text;

        if ($this->has(YapHelper::FLAG_CODE)) {
            $text = preg_replace_callback('/`([^`\n]+)`/', fn ($m) => $this->token('<code>' . $this->escape($m[1]) . '</code>'), $text) ?? $text;
        }

        // Images: ![alt](url)
        $text = preg_replace_callback('/!\[([^\]\n]{0,200})\]\(\s*(\S{1,2048}?)\s*\)/', function (array $m): string {
            if ($this->has(YapHelper::FLAG_IMAGES) && ($url = $this->safeUrl($m[2], true)) !== null) {
                return $this->token(
                    '<img class="yap-img" src="' . $this->escape($url) . '" alt="' . $this->escape($m[1]) . '" loading="lazy" decoding="async" referrerpolicy="no-referrer">'
                );
            }

            if ($this->has(YapHelper::FLAG_LINKS) && ($html = $this->link($m[2], $this->escape($m[1] !== '' ? $m[1] : $m[2]))) !== null) {
                return $this->token($html);
            }

            return $m[1];
        }, $text) ?? $text;

        // Links: [label](url)
        $text = preg_replace_callback('/\[([^\]\n]{1,300})\]\(\s*(\S{1,2048}?)\s*\)/', function (array $m): string {
            if ($this->has(YapHelper::FLAG_LINKS) && ($html = $this->link($m[2], $this->emphasis($this->escape($m[1])))) !== null) {
                return $this->token($html);
            }

            return $m[1];
        }, $text) ?? $text;

        if ($this->has(YapHelper::FLAG_LINKS)) {
            $text = $this->autolink($text);
        }

        return $this->emphasis($this->escape($text));
    }

    /**
     * Turn bare URLs into links.
     */
    private function autolink(string $text): string
    {
        return preg_replace_callback('#(?<![\w/"=])https?://[^\s<>"\x1A]+#i', function (array $m): string {
            $url   = $m[0];
            $trail = '';

            // Leave trailing punctuation outside the link.
            while ($url !== '' && strpbrk(substr($url, -1), '.,;:!?\'*') !== false
                || (str_ends_with($url, ')') && substr_count($url, '(') < substr_count($url, ')'))) {
                $trail = substr($url, -1) . $trail;
                $url   = substr($url, 0, -1);
            }

            $html = $this->link($url, $this->escape($this->shorten($url)));

            return ($html !== null ? $this->token($html) : $url) . $trail;
        }, $text) ?? $text;
    }

    /**
     * Emphasis on already-escaped text.
     */
    private function emphasis(string $html): string
    {
        $rules = [
            '/\*\*(?=\S)(.+?)(?<=\S)\*\*/u'                 => '<strong>$1</strong>',
            '/(?<![\w])__(?=\S)(.+?)(?<=\S)__(?![\w])/u'    => '<strong>$1</strong>',
            '/(?<![\*\w])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![\*\w])/u' => '<em>$1</em>',
            '/(?<![\w])_(?=\S)(.+?)(?<=\S)_(?![\w])/u'      => '<em>$1</em>',
            '/~~(?=\S)(.+?)(?<=\S)~~/u'                     => '<del>$1</del>',
            '/\|\|(?=\S)(.+?)(?<=\S)\|\|/u'                 => '<span class="yap-spoiler" tabindex="0" role="button">$1</span>',
        ];

        foreach ($rules as $pattern => $replacement) {
            $html = preg_replace($pattern, $replacement, $html) ?? $html;
        }

        return $html;
    }

    /**
     * Build an anchor, or null if the URL is not acceptable.
     */
    private function link(string $url, string $labelHtml): ?string
    {
        $url = $this->safeUrl($url);

        if ($url === null) {
            return null;
        }

        $host     = strtolower((string) parse_url($url, PHP_URL_HOST));
        $internal = $host === $this->siteHost;
        $rel      = [];

        if (!$internal || !$this->params->get('internal_follow', 1)) {
            $rel = preg_split('/\s+/', trim((string) $this->params->get('link_rel', 'ugc nofollow')), -1, PREG_SPLIT_NO_EMPTY);
        }

        $blank = !$internal && $this->params->get('link_blank', 1);

        if ($blank) {
            $rel[] = 'noopener';
        }

        return '<a class="yap-link" href="' . $this->escape($url) . '"'
            . ($rel ? ' rel="' . $this->escape(implode(' ', array_unique($rel))) . '"' : '')
            . ($blank ? ' target="_blank"' : '')
            . '>' . $labelHtml . '</a>';
    }

    /**
     * Validate a URL: http(s) only (https only for images), a real host,
     * and the configured allow/block list.
     */
    public function safeUrl(string $url, bool $image = false): ?string
    {
        $url = trim($url, " \t<>");

        if ($url === '' || \strlen($url) > 2048 || preg_match('/[\s\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        $parts  = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = strtolower($parts['host'] ?? '');

        if (!\in_array($scheme, $image ? ['https'] : ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (!preg_match('/^[\p{L}\p{N}.-]+$/u', $host) && !filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return null;
        }

        $mode = $this->params->get('domain_mode', 'none');

        if ($mode !== 'none' && $host !== $this->siteHost) {
            $listed = $this->matchesDomain($host);

            if (($mode === 'allow' && !$listed) || ($mode === 'block' && $listed)) {
                return null;
            }
        }

        return $url;
    }

    private function matchesDomain(string $host): bool
    {
        foreach ($this->domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    private function shorten(string $url): string
    {
        $display = preg_replace('#^https?://(www\.)?#i', '', $url);

        return mb_strlen($display) > 60 ? mb_substr($display, 0, 45) . '…' . mb_substr($display, -10) : $display;
    }

    private function has(int $flag): bool
    {
        return ($this->flags & $flag) === $flag;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function token(string $html): string
    {
        $this->tokens[] = $html;

        return self::TOKEN . (\count($this->tokens) - 1) . self::TOKEN;
    }

    private function restore(string $html): string
    {
        $pattern = '/' . self::TOKEN . '(\d+)' . self::TOKEN . '/';

        // Tokens can contain tokens (e.g. emphasis inside link labels), so resolve a few levels.
        for ($pass = 0; $pass < 5 && str_contains($html, self::TOKEN); $pass++) {
            $html = preg_replace_callback($pattern, fn ($m) => $this->tokens[(int) $m[1]] ?? '', $html) ?? $html;
        }

        return str_replace(self::TOKEN, '', $html);
    }
}
