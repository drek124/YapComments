<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;

/**
 * The component has no pages of its own; threads are shown by the content plugin.
 */
class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = [])
    {
        throw new \Exception(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
    }
}
