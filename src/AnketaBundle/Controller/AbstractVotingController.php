<?php

namespace AnketaBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class AbstractVotingController extends Controller {

    public function preExecute() {
        $access = $this->get('anketa.access.hlasovanie');
        $user = $access->getUser();
        $userSeason = $access->getUserSeason();
        if ($user === null || $userSeason === null) throw new AccessDeniedException();

        if (!$userSeason->getLoadedFromAis()) {
            $userSeason->setLoadedFromAis(TRUE);
            $this->get('anketa.user_provider')->loadUserInfo($user, array('isStudentThisSeason', 'subjects'));
        }

        if ($access->userCanVote()) return;

        if (!$access->isVotingOpen()) throw new AccessDeniedException();

        if (!$access->userIsStudent()) {
            // Zistime, ake ma zapisne listy v tomto semestri
            $semestre = $userSeason->getSeason()->getAisSemesterList();
            if (empty($semestre)) {
                    throw new \Exception("Sezona nema nastavene aisSemesters");
            }
            $retriever = $this->get('anketa.ais_retriever');
            $faculty = $this->container->getParameter('org_unit');
            $result = $retriever->getResult($faculty, $semestre);


            $rv = $this->render('AnketaBundle:Hlasovanie:novote.html.twig', array('ostatne_studia' => $result['ostatne_studia']));
            $rv->setStatusCode(403);
            return $rv;
        }

        if ($access->getUserSeason()->getFinished()) {
            $rv = $this->render('AnketaBundle:Hlasovanie:dakujeme.html.twig');
            $rv->setStatusCode(403);
            return $rv;
        }

        // Nemoze hlasovat z nejakeho ineho dovodu...
        throw new AccessDeniedException();
    }
}
