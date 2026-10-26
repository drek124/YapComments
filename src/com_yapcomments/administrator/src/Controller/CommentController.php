<?php

/**
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @copyright   (C) 2026 drek124. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Drek124\Component\YapComments\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\FormController;

/**
 * Single comment edit controller.
 */
class CommentController extends FormController
{
    protected $text_prefix = 'COM_YAPCOMMENTS_COMMENT';

    /**
     * Comments are created on the site only.
     */
    protected function allowAdd($data = [])
    {
        return false;
    }
}
