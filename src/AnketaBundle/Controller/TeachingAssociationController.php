<?php
/**
 * @copyright Copyright (c) 2013 The FMFI Anketa authors (see AUTHORS).
 * Use of this source code is governed by a license that can be
 * found in the LICENSE file in the project root directory.
 *
 * @package    Anketa
 * @subpackage Controller
 *
 */

namespace AnketaBundle\Controller;

use AnketaBundle\Entity\User;
use AnketaBundle\Entity\TeachingAssociation;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TeachingAssociationController extends Controller
{

    public function preExecute() {
        if (!$this->get('anketa.access.hlasovanie')->userCanVote())
            throw new AccessDeniedException();
    }

    public function formAction($subject_slug) {
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('AnketaBundle\Entity\Subject');
        $subject = $repo->findOneBy(array('slug' => $subject_slug));
        if ($subject === null) {
            throw new NotFoundHttpException('Chybny slug predmetu: ' . $subject_slug);
        }

        return $this->render('AnketaBundle:TeachingAssociation:form.html.twig',
                array('subject'=>$subject));
    }

    public function processFormAction($subject_slug) {
        $request = $this->get('request');
        $em = $this->get('doctrine.orm.entity_manager');
        $subjectRepository = $em->getRepository('AnketaBundle\Entity\Subject');
        $seasonRepository = $em->getRepository('AnketaBundle\Entity\Season');
        $userRepository = $em->getRepository('AnketaBundle\Entity\User');

        $season = $seasonRepository->getActiveSeason();
        $subject = $subjectRepository->findOneBy(array('slug' => $subject_slug));
        if ($subject === null) {
            throw new NotFoundHttpException('Chybny slug predmetu: ' . $subject_slug);
        }
        $security = $this->get('security.context');
        $user = $security->getToken()->getUser();

        $note = $request->request->get('note', '');
        $is_lecturer = $request->request->get('teacher', false);
        $is_trainer = $request->request->get('teacher-assistant', false);
        $teacher_login = $request->get('teacher-login', null);
        $teacher_name = $request->get('teacher-name', null);
        $teacher_givenName = '';
        $teacher_familyName = '';

        if ($teacher_name === null)
            return new Response('Required parameter "teacher-name" is missing.',
                    400);

        // validate teacher login and get given and family names
        $teacher = null;
        if ($teacher_login !== null) {
            // if $teacher_login is not null, it should have been set up by
            // quering LDAP with $teacher_name, but we want to make sure, that
            // it was the case and the login was not changed afterwards
            
            // first validate login by looking up in DB
            $teacher = $userRepository->findOneBy(
                    array('login' => $teacher_login));
            
            if ($teacher === null) {
                // if not found in DB, try LDAP
                $ldapSearch = $this->container->get('anketa.teacher_search');
                $teacher_info = $ldapSearch->byLogin($teacher_login);
                
                if (!empty($teacher_info)
                        && array_key_exists($teacher_login, $teacher_info)) {
                    $teacher_givenName = $teacher_info[$teacher_login]['givenName'];
                    $teacher_familyName = $teacher_info[$teacher_login]['familyName'];
                } else {
                    // $teacher_login does not exists, we'll save the provided
                    // teacher's name into note
                    $note .= PHP_EOL.sprintf(
                            'Učiteľ "%s" bol zadaný s loginom, ktorý nie je v '.
                            'LDAP-e (potenciálny pokus o podvod).',
                            $teacher_name);
                }
            }
        }

        // add a user when he/she is not in DB, but found in LDAP
        if ($teacher === null && $teacher_login !== null
                && !empty($teacher_givenName)) {
            $teacher = new User($teacher_login);
            $teacher->setDisplayName($teacher_name);
            $teacher->setGivenName($teacher_givenName);
            $teacher->setFamilyName($teacher_familyName);

            $em->persist($teacher);
            $em->flush();
        }

        // create "ticket" for the association
        $assoc = new TeachingAssociation($season, $subject, $teacher, $user,
                $note, $is_lecturer, $is_trainer);
        $em->persist($assoc);
        $em->flush();

        // send email about the request
        $emailTpl = array(
                'subject' => $subject,
                'teacher' => $teacher,
                'is_lecturer' => $is_lecturer,
                'is_trainer' => $is_trainer,
                'note' => $note,
                'user' => $user);
        $sender = $this->container->getParameter('mail_sender');
        $to = $this->container->getParameter('mail_dest_new_teaching_association');
        $body = $this->renderView('AnketaBundle:TeachingAssociation:email.txt.twig', $emailTpl);
        $skratkaFakulty = $this->container->getParameter('skratka_fakulty');

        $this->get('mailer'); // DO NOT DELETE THIS LINE
        // it autoloads required things before Swift_Message can be used

        $message = \Swift_Message::newInstance()
                ->setSubject($skratkaFakulty . ' ANKETA -- requested teacher')
                ->setFrom($sender)
                ->setTo($to)
                ->setBody($body);
        $this->get('mailer')->send($message);

        // display message
        // email will be send after completing the request in admin section
        $uni_email = $user->getLogin().'@uniba.sk';
        $this->get('session')->getFlashBag()->add('success',
                'Ďakujeme za informáciu. ' .
                'V priebehu pár dní by mala byť spracovaná. Následne Vás budeme
                informovať správou na Váš univerzitný email ('.$uni_email.').');

        return new RedirectResponse($this->generateUrl(
                'answer_subject', array('subject_slug'=>$subject->getSlug())));
    }

}
