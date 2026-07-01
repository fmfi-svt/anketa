<?php

namespace AnketaBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;

class ApiController extends Controller {

    public function resultsAction() {
        $request = $this->get('request');
        if ($request->getMethod() != 'POST') {
            throw new \BadMethodCallException('POST request required.');
        }
        $codes = $request->get('codes');
        if (!is_array($codes)) {
            throw new \InvalidArgumentException('Array of codes is missing.');
        }

        $em = $this->get('doctrine.orm.entity_manager');
        $data = $em->getRepository('AnketaBundle:Answer')
                   ->getMostRecentAverageEvaluations($codes);
        $averages = array();
        foreach ($data as $row) {
            $slug = $row['season_slug'].'/predmet/'.$row['subject_slug'];
            $url = $this->get('router')->generate('statistics_results', array('section_slug' => $slug), true);
            $averages[$row['code']] = array(
                'average' => $row['average'],
                'votes' => $row['votes'],
                'season' => $row['season_slug'],
                'url' => $url,
            );
        }
        return new Response(json_encode($averages));
    }

    public function ratingsAction() {
        $request = $this->get('request');
        $user = $this->get('anketa.access.statistics')->getUser();
        if ($request->getMethod() != 'POST' && $user === null) {
            throw new \BadMethodCallException('Not authorized');
        }
        $em = $this->get('doctrine.orm.entity_manager');
        $data = $em->getRepository('AnketaBundle:Answer')
                   ->getSubjectsRatings();
        return new Response(json_encode($data));
    }

}
