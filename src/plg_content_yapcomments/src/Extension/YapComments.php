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
use Drek124\Component\YapComments\Site\Helper\Icons;
use Drek124\Component\YapComments\Site\Helper\ThreadRenderer;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Content\AfterDisplayEvent;
use Joomla\CMS\Event\Content\BeforeDisplayEvent;
use Joomla\CMS\Event\Model\AfterDeleteEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;

/**
 * Shows YapComments threads below articles.
 */
final class YapComments extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    private const CONTEXT = 'com_content.article';

    /** @var array<int, int> */
    private array $counts = [];

    public static function getSubscribedEvents(): array
    {
        return [
            'onContentAfterDisplay'  => 'onContentAfterDisplay',
            'onContentBeforeDisplay' => 'onContentBeforeDisplay',
            'onContentPrepareForm'   => 'onContentPrepareForm',
            'onContentAfterDelete'   => 'onContentAfterDelete',
        ];
    }

    /**
     * Append the thread to a single article page.
     */
    public function onContentAfterDisplay(AfterDisplayEvent $event): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        $item  = $event->getItem();

        if ($event->getContext() !== self::CONTEXT || !$app->isClient('site') || !$this->available()
            || $input->getCmd('option') !== 'com_content' || $input->getCmd('view') !== 'article'
            || (int) $input->getInt('id') !== (int) ($item->id ?? 0) || $input->getBool('print')) {
            return;
        }

        $resolved = ContextResolver::resolve(self::CONTEXT, (int) $item->id);

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
     * A small comment counter on blog and featured listings.
     */
    public function onContentBeforeDisplay(BeforeDisplayEvent $event): void
    {
        $item = $event->getItem();

        if (!\in_array($event->getContext(), ['com_content.category', 'com_content.featured'], true)
            || !$this->params->get('show_counts', 1) || !$this->getApplication()->isClient('site')
            || empty($item->id) || !$this->available()) {
            return;
        }

        $resolved = ContextResolver::resolve(self::CONTEXT, (int) $item->id);

        if (!$resolved || $resolved->mode === ContextResolver::MODE_HIDDEN) {
            return;
        }

        $this->loadComponentLanguage();

        $count = $this->count((int) $item->id);
        $url   = Route::_(RouteHelper::getArticleRoute($item->slug ?? $item->id, $item->catid ?? 0, $item->language ?? '*')) . '#comments';

        $wa = $this->getApplication()->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_yapcomments');
        $wa->useStyle('com_yapcomments.site');

        $event->addResult(
            '<a class="yap-count-badge" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . Icons::svg('chat') . '<span>' . Text::plural('COM_YAPCOMMENTS_N_COMMENTS', $count) . '</span></a>'
        );
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
     * Remove a deleted article's comments and votes.
     */
    public function onContentAfterDelete(AfterDeleteEvent $event): void
    {
        $item = $event->getItem();

        if ($event->getContext() !== self::CONTEXT || !$this->params->get('delete_with_article', 1) || empty($item->id)) {
            return;
        }

        $db      = $this->getDatabase();
        $id      = (int) $item->id;
        $context = self::CONTEXT;

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

    private function available(): bool
    {
        return ComponentHelper::isEnabled('com_yapcomments') && YapHelper::params()->get('enabled', 1);
    }

    private function count(int $id): int
    {
        if (!isset($this->counts[$id])) {
            $model             = $this->getApplication()->bootComponent('com_yapcomments')->getMVCFactory()->createModel('Thread', 'Site', ['ignore_request' => true]);
            $this->counts[$id] = $model->countPublished(self::CONTEXT, $id);
        }

        return $this->counts[$id];
    }

    private function loadComponentLanguage(): void
    {
        $lang = $this->getApplication()->getLanguage();
        $lang->load('com_yapcomments', JPATH_SITE . '/components/com_yapcomments')
            || $lang->load('com_yapcomments', JPATH_SITE);
    }
}
