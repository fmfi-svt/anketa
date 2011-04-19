<?php
/**
 * @copyright Copyright (c) 2011 The FMFI Anketa authors (see AUTHORS).
 * Use of this source code is governed by a license that can be
 * found in the LICENSE file in the project root directory.
 *
 * @package    Anketa
 * @subpackage Anketa__Controller
 * @author     Jakub Markoš <jakub.markos@gmail.com>
 */

namespace AnketaBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

use AnketaBundle\Entity\Question;
use AnketaBundle\Entity\Option;
use AnketaBundle\Entity\Category;
use AnketaBundle\Form\AddQuestionForm;

/**
 * Controller for managing questions - adding, viewing, deleting, editing
 */

class ManageController extends Controller {

    public function indexAction()
    {
        return $this->render('AnketaBundle:Manage:layout.html.twig');
    }

    public function answerQuestionsAction() {
        $em = $this->get('doctrine.orm.entity_manager');

        // tu bude treba vytiahnut nejaku rozumnu mnozinu otazok, napr otazky
        // tykajuce sa matalyzy, tykajuce sa skoly apod - potom premenovat akciu
        // na nieco specifickejsie, pripadne pridat argument

        // zatial vyberam vsetky
        $questions = $em->getRepository('AnketaBundle:Question')->findAll();
        

        return $this->render('AnketaBundle:Manage:answerQuestions.html.twig',
                array('questions' => $questions));
        
    }
}
