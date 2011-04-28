<?php
/**
 * @copyright Copyright (c) 2011 The FMFI Anketa authors (see AUTHORS).
 * Use of this source code is governed by a license that can be
 * found in the LICENSE file in the project root directory.
 *
 * @package    Anketa
 * @subpackage Anketa__Controller__Manage
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

class ManageQuestionsController extends Controller {
    
    public function listQuestionsAction() {
       $em = $this->get('doctrine.orm.entity_manager');
       $questions = $em->getRepository('AnketaBundle:Question')->findAll();
        return $this->render('AnketaBundle:Manage:questionList.html.twig',
                              array('questions' => $questions));
    }

    public function addQuestionAction() {
        $question = new Question();

        $request = Request::createFromGlobals();

        $em = $this->get('doctrine.orm.entity_manager');

        if ('POST' == $request->getMethod()) {
            // asi bude treba nejake validacie vstupu - na druhej strane,
            // snad nebude moct hocikto pridavat otazky

            $question->setQuestion($request->request->get('_question'));

            if ($request->request->get('_stars')) {
                $question->generateStarOptions();
            } else {
                /**
                 * @todo validacie - ale treba ich tu?
                 */
                for ($i = 1; $i <= 10; $i++) {
                    $option = $request->request->get('_option' . $i);
                    $eval = $request->request->get('_eval' . $i);
                    if ($option != '') {
                        if ($eval == '') {
                            $eval = 0;
                        }
                        $question->addOption(new Option($option, $eval));
                    }
                }
            }

            $categoryId = $request->request->get('_category');
            $category = $em->find('AnketaBundle:Category', $categoryId);
            $question->setCategory($category);

            $em->persist($question);
            $em->flush();
            return new RedirectResponse($this->generateUrl('view_question',
                           array('id' => $question->getId())));
        }

        $categories = $em->getRepository('AnketaBundle:Category')->findAll();

        return $this->render('AnketaBundle:Manage:questionAdd.html.twig', array(
            'question' => $question, 'categories' => $categories,
        ));
    }

    public function viewQuestionAction($id) {
        $em = $this->get('doctrine.orm.entity_manager');
        $question = $em->find('AnketaBundle:Question', $id);
        // alternativny sposob:
//        $question = $em->getRepository('AnketaBundle\Entity\Question')->getQuestion($id);
        return $this->render('AnketaBundle:Manage:questionView.html.twig',
                              array('question' => $question));
    }
    
}
