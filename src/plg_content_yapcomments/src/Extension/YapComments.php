<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Plugin\Content\YapComments\Extension;

\defined('_JEXEC') or die;

use Drek124\Component\YapComments\Administrator\Helper\YapHelper;
use Drek124\Component\YapComments\Site\Helper\ContextResolver;
use Drek124\Component\YapComments\Site\Helper\ItemRegistry;
use Drek124\Component\YapComments\Site\Helper\ThreadRenderer;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Content\AfterDisplayEvent;
use Joomla\CMS\Event\Model\AfterDeleteEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

/**
 * Shows YapComments threads below articles and, optionally, below the main
 * item of any other extension that fires onContentAfterDisplay.
 */
final class YapComments extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    private const CONTEXT = 'com_content.article';

    public static function getSubscribedEvents(): array
    {
        return [
            'onContentAfterDisplay'  => 'onContentAfterDisplay',
            'onContentPrepareForm'   => 'onContentPrepareForm',
            'onContentAfterDelete'   => 'onContentAfterDelete',
        ];
    }

    /**
     * Append the thread to a single article page, or to the main item of any
     * other context listed in the plugin options (e.g. com_foo.item).
     */
    public function onContentAfterDisplay(AfterDisplayEvent $event): void
    {
        $app     = $this->getApplication();
        $input   = $app->getInput();
        $item    = $event->getItem();
        $context = (string) $event->getContext();
        $id      = (int) ($item->id ?? 0);

        if ($id < 1 || !$app->isClient('site') || $input->getBool('print') || !$this->available()) {
            return;
        }

        if ($context === self::CONTEXT) {
            if ($input->getCmd('option') !== 'com_content' || $input->getCmd('view') !== 'article'
                || (int) $input->getInt('id') !== $id) {
                return;
            }
        } elseif ($this->isExtraContext($context) && $this->isMainItem($context, $id)) {
            ItemRegistry::remember($context, $id, $this->describe($item));
        } else {
            return;
        }

        // Some views fire the event more than once for the same item.
        static $done = [];

        if (isset($done[$context . ':' . $id])) {
            return;
        }

        $done[$context . ':' . $id] = true;

        $resolved = ContextResolver::resolve($context, $id);

        if (!$resolved || $resolved->mode === ContextResolver::MODE_HIDDEN) {
            return;
        }

        $this->loadComponentLanguage();

        $html = (new ThreadRenderer($resolved))->block(max(1, min(50, $input->getInt('ycp', 1))), $input->getCmd('ycs'));

        if ($html !== '') {
            $event->addResult($html);
        }
    }

    /**
     * Per-article override: default / open / read-only / hidden.
     */
    public function onContentPrepareForm(PrepareFormEvent $event): void
    {
        $form = $event->getForm();

        if ($form->getName() !== 'com_content.article' || !$this->params->get('article_field', 1)) {
            return;
        }

        $form->load('<form>
            <fields name="attribs">
                <fieldset name="yapcomments" label="PLG_CONTENT_YAPCOMMENTS_FIELDSET">
                    <field name="yapcomments_mode" type="list" default="" label="PLG_CONTENT_YAPCOMMENTS_MODE" description="PLG_CONTENT_YAPCOMMENTS_MODE_DESC">
                        <option value="">PLG_CONTENT_YAPCOMMENTS_MODE_DEFAULT</option>
                        <option value="open">PLG_CONTENT_YAPCOMMENTS_MODE_OPEN</option>
                        <option value="locked">PLG_CONTENT_YAPCOMMENTS_MODE_LOCKED</option>
                        <option value="hidden">PLG_CONTENT_YAPCOMMENTS_MODE_HIDDEN</option>
                    </field>
                </fieldset>
            </fields>
        </form>');
    }

    /**
     * Remove a deleted article's (or other listed item's) comments and votes.
     */
    public function onContentAfterDelete(AfterDeleteEvent $event): void
    {
        $item    = $event->getItem();
        $context = (string) $event->getContext();

        if (($context !== self::CONTEXT && !$this->isExtraContext($context))
            || !$this->params->get('delete_with_article', 1) || empty($item->id)) {
            return;
        }

        $db = $this->getDatabase();
        $id = (int) $item->id;

        if ($context !== self::CONTEXT) {
            ItemRegistry::forget($context, $id);
        }

        $sub = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__yapcomments_comments'))
            ->where($db->quoteName('context') . ' = :ctx1')
            ->where($db->quoteName('item_id') . ' = :id1');

        $db->setQuery(
            $db->createQuery()
                ->delete($db->quoteName('#__yapcomments_votes'))
                ->where($db->quoteName('comment_id') . ' IN (SELECT * FROM (' . $sub . ') AS ' . $db->quoteName('c') . ')')
                ->bind(':ctx1', $context)
                ->bind(':id1', $id, ParameterType::INTEGER)
        )->execute();

        $db->setQuery(
            $db->createQuery()
                ->delete($db->quoteName('#__yapcomments_comments'))
                ->where($db->quoteName('context') . ' = :ctx2')
                ->where($db->quoteName('item_id') . ' = :id2')
                ->bind(':ctx2', $context)
                ->bind(':id2', $id, ParameterType::INTEGER)
        )->execute();
    }

    /**
     * The "Other contexts" option: one per line, either exact
     * ("com_foo.item") or a whole component ("com_foo.*").
     *
     * @return string[]
     */
    private function extraContexts(): array
    {
        static $list = null;

        if ($list === null) {
            $list = [];

            foreach (preg_split('/[\s,]+/', (string) $this->params->get('contexts', '')) as $entry) {
                $entry = strtolower(trim($entry));

                if ($entry !== self::CONTEXT && preg_match('/^[a-z0-9_]+\.(?:[a-z0-9_]+|\*)$/', $entry)) {
                    $list[] = $entry;
                }
            }
        }

        return $list;
    }

    private function isExtraContext(string $context): bool
    {
        if (!preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $context)) {
            return false;
        }

        $component = strstr($context, '.', true);

        foreach ($this->extraContexts() as $allowed) {
            if ($allowed === $context || $allowed === $component . '.*') {
                return true;
            }
        }

        return false;
    }

    /**
     * Only the item the page is about: the request must belong to the
     * context's component, and an "id" in the request must match the item.
     * Keeps threads off list views, modules and related-item blocks.
     */
    private function isMainItem(string $context, int $id): bool
    {
        $input = $this->getApplication()->getInput();

        if ($input->getCmd('option') !== strstr($context, '.', true)) {
            return false;
        }

        $requested = (int) $input->getInt('id');

        return $requested === 0 || $requested === $id;
    }

    /**
     * What ItemRegistry needs to resolve the item later without its extension.
     */
    private function describe(object $item): array
    {
        $mode = '';

        foreach (['attribs', 'params'] as $prop) {
            if (!empty($item->$prop) && (\is_string($item->$prop) || \is_array($item->$prop) || \is_object($item->$prop))) {
                $reg  = $item->$prop instanceof Registry ? $item->$prop : new Registry($item->$prop);
                $mode = (string) $reg->get('yapcomments_mode', $mode);
            }
        }

        $uri = clone Uri::getInstance();
        $uri->delVar('ycp');
        $uri->delVar('ycs');

        $created = $item->created ?? $item->created_time ?? '';

        return [
            'title'     => (string) ($item->title ?? $item->name ?? ''),
            'url'       => $uri->toString(['scheme', 'host', 'port', 'path', 'query']),
            'language'  => (string) ($item->language ?? '') ?: '*',
            'access'    => (int) ($item->access ?? 0),
            'author_id' => (int) ($item->created_by ?? $item->created_user_id ?? 0),
            'created'   => \is_string($created) ? $created : '',
            'mode'      => \in_array($mode, [ContextResolver::MODE_OPEN, ContextResolver::MODE_LOCKED, ContextResolver::MODE_HIDDEN], true) ? $mode : '',
        ];
    }

    private function available(): bool
    {
        return ComponentHelper::isEnabled('com_yapcomments') && YapHelper::params()->get('enabled', 1);
    }

    private function loadComponentLanguage(): void
    {
        $lang = $this->getApplication()->getLanguage();
        $lang->load('com_yapcomments', JPATH_SITE . '/components/com_yapcomments')
            || $lang->load('com_yapcomments', JPATH_SITE);
    }
}
