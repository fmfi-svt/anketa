<?php

namespace AnketaBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use AnketaBundle\Entity\Question;
use AnketaBundle\Entity\Season;
use AnketaBundle\Lib\StatisticalFunctions;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use AnketaBundle\Menu\MenuItemProgressbar;

class StatisticsController extends Controller {
    const GRAPH_PALETTE = 'ff1e1e|ff8f1e|f5f51d|b4ff1e|1eff1e';

    /**
     * @param string $season_slug if null, returns current active season
     * @return Season the season
     */
    private function getSeason($season_slug = null) {
        $em = $this->get('doctrine.orm.entity_manager');
        $repository = $em->getRepository('AnketaBundle\Entity\Season');
        if ($season_slug === null) {
            $seasonsFound = $repository->findBy(array(), array('ordering' => 'DESC'));
            $access = $this->get('anketa.access.statistics');
            $season = null;
            foreach ($seasonsFound as $candidateSeason) {
                if ($access->canSeeResults($candidateSeason) || $access->someoneCanSeeResults($candidateSeason)) {
                    $season = $candidateSeason;
                    break;
                }
            }
            if ($season == null) {
                $msg = $this->get('translator')->trans('statistics.controller.ziadna_sezona_s_vysledkami');
                throw new NotFoundHttpException($msg);
            }
        } else {
            $season = $repository->findOneBy(array('slug' => $season_slug));
        }
        if ($season == null) {
            $msg = $this->get('translator')->trans('statistics.controller.chybna_sezona', array('%slug%' => $season_slug));
            throw new NotFoundHttpException($msg);
        }
        return $season;
    }

    /**
     * Returns appropriate comments from the set of answers
     */
    public function getAppropriateComments($answers) {
        $comments = array();

        foreach ($answers as $answer) {
            if ($answer->hasComment() && !$answer->getInappropriate()) {
                $comments[] = array('answer_id' => $answer->getId(),
                                    'comment' => $answer->getComment());
            }
        }

        return $comments;
    }

    /**
     * Returns number of inappropriate comments in set of answers.
     * @param type $answers
     * @return int
     */
    public function getNumberOfInappropriateComments($answers) {
        $amount = 0;
        foreach ($answers as $answer) if ($answer->hasComment() && $answer->getInappropriate()) $amount++;
        return $amount;
    }

    /**
     * @return array(array('cnt'=>int,'title'=>string,'value'=>double))
     */
    public function getHistogramData($question, $answers) {
        if (!$question->hasOptions()) {
            return array();
        }

        $options = $question->getOptions();
        $histogram = array();
        $map = array();
        $i = 0;
        foreach ($options as $option) {
            $map[$option->getId()]=$i;
            $histogram[$i] =
                array('cnt' => 0,
                      'title' => $option->getOption($this->getRequest()->getLocale()),
                      'value' => $option->getEvaluation()
                      );
            $i++;
        }

        foreach ($answers as $answer) {
            if ($answer->hasOption()) {
                $histogram[$map[$answer->getOption()->getId()]]['cnt'] += 1;
            }
        }

        return $histogram;
    }

    /**
     * Creates chart for given histogram
     */
    public function getChart(Question $question, $histogram) {
        $width = 300;
        $height = 150;

        list($c1, $c2, $c3, $c4, $c5) = explode('|', self::GRAPH_PALETTE);

        if (count($histogram) == 2) {
            $palette = array($c5, $c1);   // the first choice is the best
        }
        else if (count($histogram) == 5 && $histogram[2]['value'] == 0) {
            $palette = array($c1, $c4, $c5, $c4, $c1);   // the middle choice is the best
        }
        else if (count($histogram) == 5 && $histogram[0]['value'] > $histogram[4]['value']) {
            $palette = array($c5, $c4, $c3, $c2, $c1);   // the first choice is the best
        }
        else if (count($histogram) == 5) {
            $palette = array($c1, $c2, $c3, $c4, $c5);   // the last choice is the best
        }
        else if (count($histogram) == 4 && $histogram[0]['value'] > $histogram[3]['value']) {
            $palette = array($c5, $c4, $c2, $c1);   // the first choice is the best
        }
        else {
            $palette = array();
        }

        /* when there are no votes we terminate early */
        $counts = array_map(function ($data) { return $data['cnt']; }, $histogram);
        if (array_sum($counts) == 0) {
            return null;
        }

        /*
         * We need to create JSON like this:
         * [
         *   ['Name', 'Value', { role: 'style' } ],
         *   ['title1', 1, 'color: #ff1e1e'],
         *   ['title2', 5, 'color: #ff8f1e'],
         *   ['title3', 10, 'color: #f5f51d'],
         *   ['title4', 14, 'color: #b4ff1e'],
         *   ['title5', 12, 'color: #1eff1e'],
         * ]
         */
        $bars = array_map(function ($data) use (&$palette) {
            /* we can shift the palette array because it should have the same length as histogram */
            return array($data['title'], $data['cnt'], array_shift($palette));
        }, $histogram);

        /* we prepend the array with header row */
        array_unshift($bars, array('Name', 'Value', array('role' => 'style')));

        return array('json' => json_encode($bars), 'width' => $width, 'height' => $height);
    }

    /**
     * Returns statistics for given data
     *
     * @return array with following items:
     *  - cnt count
     *  - avg (optional) average value
     *  - median (optional) mean value
     *  - sigma (optional) standard deviation
     */
    public function getStatistics(array $histogram) {
        $data = array_map(function ($x) {return array($x['value'], $x['cnt']);}, $histogram);
        $cnt = StatisticalFunctions::cnt($data);
        $stats['cnt'] = $cnt;
        if ($cnt > 0) {
            $stats['avg'] = StatisticalFunctions::average($data);
            $stats['median'] = StatisticalFunctions::median($data);
        }
        if ($cnt > 1) {
            $stats['sigma'] = StatisticalFunctions::stddev($data);
        }
        return $stats;
    }

    /**
     * Check if the array contains at least one pair of different values
     * @param array $array
     * @return boolean true if the array contains at least two different values
     */
    private function hasMoreThanOneValue($array)
    {
        $first = true;
        $prev = null;
        foreach ($array as $value) {
            if (!$first) {
                if ($prev !== $value) {
                    return true;
                }
            }
            $prev = $value;
            $first = false;
        }
        return false;
    }

    /**
     * Function returns the statistics for a question in a format:
     *      result['results'] - array of number of options indexed by option id
     *      result['chart'] - chart of the results, or null if question has no options
     *      result['comments'] - array of comments
     *
     * @param Question $question
     * @param array $answers
     *
     * @return array see function description for more info
     */
    public function processQuestion(Question $question, $answers) {
        $histogram = $this->getHistogramData($question, $answers);
        $stats = $this->getStatistics($histogram);
        $comments = $this->getAppropriateComments($answers);
        $hasComments = count($comments) > 0;
        $hasAnsweredOption = $stats['cnt'] > 0;
        if ($hasAnsweredOption) {
            foreach ($histogram as $key=>$value) {
                $histogram[$key]['portion'] = $value['cnt'] / $stats['cnt'];
            }
        }
        $evaluations = array();
        foreach ($question->getOptions() as $option) {
            $evaluations[] = $option->getEvaluation();
        }
        $hasDifferentOptions = $this->hasMoreThanOneValue($evaluations);
        if (!$hasDifferentOptions) {
            // Nema zmysel prezentovat zlozitejsiu statistiku pocitanu z rovnakych hodnot
            $stats = array('cnt' => $stats['cnt']);
        }
        if (!$hasAnsweredOption) {
            $histogram = array();
        }
        $locale = $this->getRequest()->getLocale();
        $data = array(
                'id' => $question->getId(),
                'title' => $question->getQuestion($locale),
                'description' => $question->getDescription($locale),
                'commentsAllowed' => $question->getHasComment(),
                'hasAnswer' => $hasAnsweredOption || $hasComments,
                'hasDifferentOptions' => $hasDifferentOptions,
                'comments' => $comments,
                'histogram' => $histogram,
                'chart' => $this->getChart($question, $histogram),
                'stats' => $stats,
                'numberOfInappropriateComments' => $this->getNumberOfInappropriateComments($answers),
                );

        return $data;
    }

    private function accessDeniedForSeason(Season $season) {
        $templateParams = array();
        $templateParams['activeMenuItems'] = array($season->getId());
        $templateParams['season'] = $season;
        $templateParams['logged_in'] = ($this->get('anketa.access.statistics')->getUser() !== null);
        return $this->render('AnketaBundle:Statistics:resultsDenied.html.twig', $templateParams);
    }

    public function listSubjectsAction($season_slug) {
        // TODO: slugifier ako service
        $slugifier = new \AnketaBundle\Lib\Slugifier();
        $em = $this->get('doctrine.orm.entity_manager');

        $season = $this->getSeason($season_slug);
        if (!$this->get('anketa.access.statistics')->canSeeResults($season)) {
            return $this->accessDeniedForSeason($season);
        }

        $categories = $em->getRepository('AnketaBundle:Subject')->getCategorizedSubjects($season);

        $items = array();
        foreach ($categories as $category_id => $subjects) {
            $links = array();
            foreach ($subjects as $subject) {
                $section = StatisticsSection::makeSubjectSection($this->container, $season, $subject);
                $links[$section->getTitle()] = $section->getStatisticsPath();
            }
            $items[$category_id] = array('anchor' => $slugifier->slugify($category_id), 'list' => $links);
        }

        $templateParams = array();
        $templateParams['class'] = 'subject-listing';
        $templateParams['activeMenuItems'] = array($season->getId(), 'subjects');
        $templateParams['items'] = $items;
        return $this->render('AnketaBundle:Statistics:subjectListing.html.twig', $templateParams);
    }

    public function listMySubjectsAction($season_slug) {
        $access = $this->get('anketa.access.statistics');
        $season = $this->getSeason($season_slug);
        if (!$this->get('anketa.access.statistics')->canSeeResults($season)) throw new AccessDeniedException();
        if (!$access->hasOwnSubjects($season)) throw new AccessDeniedException();
        $teacher = $access->getUser();

        if ($teacher === null) {
            $msg = $this->get('translator')->trans('statistics.controller.nenajdeny_ucitel');
            throw new NotFoundHttpException($msg);
        }

        $em = $this->get('doctrine.orm.entity_manager');
        $subjects = $em->getRepository('AnketaBundle:Subject')->getSubjectsForTeacherWithAnyAnswers($teacher, $season);

        $items = array();
        foreach ($subjects as $subject) {
            $section = StatisticsSection::makeSubjectSection($this->container, $season, $subject);
            $items[$section->getTitle()] = $section->getStatisticsPath();
        }

        $templateParams = array();
        $templateParams['title'] = $this->get('translator')->trans('statistics.controller.moje_predmety');
        $templateParams['activeMenuItems'] = array($season->getId(), 'my_subjects');
        $templateParams['items'] = array('' => $items);
        return $this->render('AnketaBundle:Statistics:listing.html.twig', $templateParams);
    }

    public function listStudyProgramsAction($season_slug) {
        $em = $this->get('doctrine.orm.entity_manager');

        $season = $this->getSeason($season_slug);
        if (!$this->get('anketa.access.statistics')->canSeeResults($season)) {
            return $this->accessDeniedForSeason($season);
        }

        $studyPrograms = $em->getRepository('AnketaBundle:StudyProgram')->getAllWithAnswers($season);

        $items = array();
        foreach ($studyPrograms as $studyProgram) {
            $section = StatisticsSection::makeStudyProgramSection($this->container, $season, $studyProgram);
            $items[$section->getTitle()] = $section->getStatisticsPath();
        }
        
        if (count($items) == 0) {
            $msg = $this->get('translator')->trans('statistics.controller.nenajdeny_studijny_program');
            throw new NotFoundHttpException($msg);
        }

        $templateParams = array();
        $templateParams['title'] = $this->get('translator')->trans('statistics.controller.studijne_programy');
        $templateParams['activeMenuItems'] = array($season->getId(), 'study_programs');
        $templateParams['items'] = array('' => $items);
        return $this->render('AnketaBundle:Statistics:listing.html.twig', $templateParams);
    }

    public function resultsAction($section_slug) {
        $section = StatisticsSection::getSectionFromSlug($this->container, $section_slug);
        if (!$this->get('anketa.access.statistics')->canSeeResults($section->getSeason())) {
            return $this->accessDeniedForSeason($section->getSeason());
        }
        
        // Skontroluje, ci dana stranka predmetu/ucitela nema byt skryta, lebo ucitel nesuhlasi
        // so zverejnenim svojich vysledkov, pripadne kvoli FaF obmedzeniu.
        if (!$section->getSeason()->getShowAllResults() && ($section->getSeason()->getFafRestricted() || $section->getTeacherOptedOut()) && !$this->get('security.context')->isGranted('ROLE_ADMIN')) {
            $em = $this->get('doctrine.orm.entity_manager');
            $access = $this->get('anketa.access.statistics');
            $season = $section->getSeason();
            $teacher = $access->getUser();
            $subjects = $em->getRepository('AnketaBundle:Subject')->getSubjectsForTeacherWithAnyAnswers($teacher, $season);
            $good = false;
            if ($section->getSubject() !== null) {
                
                // Pozrieme, ci prihlaseny uzivatel neucil v tomto semestri tento predmet
                foreach ($subjects as $subject) {
                    if ($section->getSubject() == $subject) $good = true;
                }

                // Ak ide priamo o stranku ucitela, bude ju vidiet iba on.
                if ($section->getTeacher() !== null && $section->getTeacher() !== $teacher) {
                    $good = false;
                }
            }
            if (!$good && $section->getSeason()->getFafRestricted()) {
                return $this->render('AnketaBundle:Statistics:fafRestriction.html.twig',
                    array('section' => $section, 'isteacher' => $access->isATeacher($season)));
            }
            if (!$good && $section->getTeacherOptedOut()) {
                return $this->render('AnketaBundle:Statistics:teacherOptedOut.html.twig', array(
                    'section' => $section,
                    'hide_if_all' => ($season->getSubjectHiding() == Season::HIDE_SUBJECT_IF_ALL)));
            }
        }
        
        // Ak si ucitel nepraje zverejnit svoje vysledky v ankete, neuvidi vysledky ostatnych ucitelov
        // TODO: Dorobit to tak, aby rozumne bralo do uvahy SubjectHiding (nieco v style "ja neuvidim ekvivalent toho, co ini nevidia kvoli mne"
        if (!$section->getSeason()->getShowAllResults() && $this->getUser()->getHideAllResults() && $this->get('anketa.access.statistics')->isFacultyTeacherAtAnyTime() && !$this->get('security.context')->isGranted('ROLE_ADMIN')) {
            $good = true;
            if ($section->getTeacher() !== null) {
                $em = $this->container->get('doctrine.orm.entity_manager');
                $teacher = $this->getUser();

                if ($section->getTeacher() !== $teacher) $good = false;
            }
            if (!$good) {
                return $this->render('AnketaBundle:Statistics:teacherOptedOut.html.twig', array(
                'section' => $section,
                'isTeacher' => true));
            }
        }

        $maxCnt = 0;
        $results = array();

        $questions = $section->getQuestions();
        foreach ($questions as $question) {
            $answers = $section->getAnswers($question);
            $data = $this->processQuestion($question, $answers);
            $maxCnt = max($maxCnt, $data['stats']['cnt']);
            $results[] = $data;
        }

        $templateParams = array();
        $templateParams['section'] = $section;
        $templateParams['responses'] = $section->getResponses();

        $limit = $section->getMinVoters();
        if ($maxCnt >= $limit || $this->get('anketa.access.statistics')->hasFullResults()) {
            $templateParams['results'] = $results;
            return $this->render('AnketaBundle:Statistics:results.html.twig', $templateParams);
        }
        else {
            $templateParams['limit'] = $limit;
            return $this->render('AnketaBundle:Statistics:requestResults.html.twig', $templateParams);
        }
    }
    
    public function seasonReportAction($season_slug = null) {
        $em = $this->get('doctrine.orm.entity_manager');
        $season = $this->getSeason($season_slug);
        if (!$this->get('anketa.access.statistics')->canSeeResults($season)) {
            return $this->accessDeniedForSeason($season);
        }
        
        $total = $season->getStudentCount();
        $voters = $em->getRepository('AnketaBundle\Entity\User')
                     ->getNumberOfVoters($season);
        
        $templateParams = array();
        $templateParams['voters'] = $voters;
        $templateParams['season'] = $season;
        $templateParams['voteProgress'] = new MenuItemProgressbar(null, $total, $voters);
        $templateParams['activeMenuItems'] = array($season->getId());
        return $this->render('AnketaBundle:Statistics:seasonReport.html.twig', $templateParams);
    }

    /*
     * Provides analytics stats for user interaction with anketa
     */
    public function analyticsStatsAction($season_slug = null) {
        $season = $this->getSeason($season_slug);
        $templateParams = array();
        $templateParams['season_slug'] = $season->getSlug();
        $templateParams['activeMenuItems'] = array($season->getId());
        return $this->render('AnketaBundle:Statistics:stats.html.twig', $templateParams);
    }

    /*
     * Does a CSV export of startTimestamp dates aggregated on a given day
     */
    public function analyticsStatsStartedDailyAction($season_slug = null) {
        $em = $this->get('doctrine.orm.entity_manager');
        $conn = $em->getConnection();
        $season = $this->getSeason($season_slug);

        $st = $conn->prepare("select date_format(UserSeason.startTimestamp, '%Y-%m-%d') as dates, count(*) as counts from UserSeason where UserSeason.startTimestamp is not NULL and UserSeason.season_id = :season_id group by date_format(UserSeason.startTimestamp, '%Y-%m-%d') order by date_format(UserSeason.startTimestamp, '%Y-%m-%d')");
        $st->bindValue('season_id', $season->getId());
        $st->execute();

        $template_params = array();
        $template_params['results'] = $st->fetchAll();
        return $this->render('AnketaBundle:Statistics:started_daily.csv.twig', $template_params);
    }

    /*
     * Does a CSV export of startTimestamp dates aggregated on a given day
     */
    public function analyticsStatsFinishedDailyAction($season_slug = null) {
        $em = $this->get('doctrine.orm.entity_manager');
        $conn = $em->getConnection();
        $season = $this->getSeason($season_slug);

        $st = $conn->prepare("select date_format(UserSeason.finishTimestamp, '%Y-%m-%d') as dates, count(*) as counts from UserSeason where UserSeason.finishTimestamp is not NULL and UserSeason.season_id = :season_id group by date_format(UserSeason.finishTimestamp, '%Y-%m-%d') order by date_format(UserSeason.finishTimestamp, '%Y-%m-%d')");
        $st->bindValue('season_id', $season->getId());
        $st->execute();

        $template_params = array();
        $template_params['results'] = $st->fetchAll();
        return $this->render('AnketaBundle:Statistics:started_daily.csv.twig', $template_params);
    }

    /*
     * Does a CSV export of startTimestamp dates aggregated on a given weekday/hour
     */
    public function analyticsStatsStartedWeekdayHourAction($season_slug = null) {
        $em = $this->get('doctrine.orm.entity_manager');
        $conn = $em->getConnection();
        $season = $this->getSeason($season_slug);

        $st = $conn->prepare("select HOUR(UserSeason.startTimestamp) as hour, DAYOFWEEK(UserSeason.startTimestamp) as weekday, count(*) as counts from UserSeason where UserSeason.startTimestamp is not NULL and UserSeason.season_id = :season_id group by weekday, hour");
        $st->bindValue('season_id', $season->getId());
        $st->execute();

        $template_params = array();
        $template_params['results'] = $st->fetchAll();
        return $this->render('AnketaBundle:Statistics:started_weekdays_hours.csv.twig', $template_params);
    }

    /*
     * Does a CSV export of startTimestamp dates aggregated on a given day
     */
    public function analyticsTimeTakenToFinishAction($season_slug = null) {
        $em = $this->get('doctrine.orm.entity_manager');
        $conn = $em->getConnection();
        $season = $this->getSeason($season_slug);

        $st = $conn->prepare("select (UNIX_TIMESTAMP(finishTimestamp) - UNIX_TIMESTAMP(startTimestamp)) div 300 as dt, count(*) as counts from UserSeason where finishTimestamp is not null and startTimestamp is not null and season_id = :season_id group by dt");
        $st->bindValue('season_id', $season->getId());
        $st->execute();

        $template_params = array();
        $template_params['results'] = $st->fetchAll();
        return $this->render('AnketaBundle:Statistics:time_taken_to_finish.csv.twig', $template_params);
    }



    public function listGeneralAction($season_slug) {
        $em = $this->get('doctrine.orm.entity_manager');
        $season = $this->getSeason($season_slug);
        if (!$this->get('anketa.access.statistics')->canSeeResults($season)) {
            return $this->accessDeniedForSeason($season);
        }

        $locale = $this->getRequest()->getLocale();
        $items = array();
        $categories = $em->getRepository('AnketaBundle\Entity\Category')
                         ->getOrderedGeneral($season);
        foreach ($categories as $category) {
            $items[$category->getDescription($locale)] = array();
            $questions = $em->getRepository('AnketaBundle:Question')->getOrderedQuestions($category, $season);
            foreach ($questions as $question) {
                $section = StatisticsSection::makeGeneralSection($this->container, $season, $question);
                $items[$category->getDescription($locale)][$question->getQuestion($locale)] = $section->getStatisticsPath();
            }
        }

        $templateParams = array();
        $templateParams['title'] = $this->get('translator')->trans('statistics.controller.vseobecne');
        $templateParams['activeMenuItems'] = array($season->getId(), 'general');
        $templateParams['items'] = $items;
        return $this->render('AnketaBundle:Statistics:listing.html.twig', $templateParams);
    }

    public function officialStatementAction($season_slug) {
        $season = $this->getSeason($season_slug);
        $url = $season->getOfficialStatement();
        if (!$url) throw new NotFoundHttpException('Not found');
        if (strpos($url, '/') === false) {
            $url = $this->get('templating.helper.assets')->getUrl('bundles/anketa/files/' . $url);
        }
        return new RedirectResponse($url);
    }

    public function flagInappropriateAction($answer_id) {
        $em = $this->get('doctrine.orm.entity_manager');
        $request = $this->get('request');

        $answer = $em->getRepository('AnketaBundle\Entity\Answer')->find($answer_id);
        if ($answer === null) {
            $msg = $this->get('translator')->trans('statistics.controller.odpoved_neexistuje', array('%id%' => $answer_id));
            throw new NotFoundHttpException($msg);
        }
        if ($answer->getInappropriate()) {
            $msg = $this->get('translator')->trans('statistics.controller.odpoved_je_skryta', array('%id%' => $answer_id));
            throw new NotFoundHttpException($msg);
        }
        $comment = $answer->getComment();
        if (empty($comment)) {
            $msg = $this->get('translator')->trans('statistics.controller.odpoved_nema_komentar', array('%id%' => $answer_id));
            throw new NotFoundHttpException($msg);
        }

        $section = StatisticsSection::getSectionOfAnswer($this->container, $answer);

        if ('POST' == $request->getMethod()) {
            $user = $this->get('anketa.access.statistics')->getUser();
            if (!$user) throw new AccessDeniedException();
            $answer->setReviewed(false);
            $em->flush();
            $note = $request->get('note', '');

            $emailTpl = array(
                    'answer_id' => $answer_id,
                    'comment_page' => $section->getStatisticsPath(true),
                    'comment_body' => $comment,
                    'note' => $note,
                    'user' => $user);
            $sender = $this->container->getParameter('mail_sender');
            $to = $this->container->getParameter('mail_dest_new_teaching_association');    // ten isty e-mail ako teaching associations
            $body = $this->renderView('AnketaBundle:Statistics:flagMail.txt.twig', $emailTpl);

            $this->get('mailer'); // DO NOT DELETE THIS LINE
            // it autoloads required things before Swift_Message can be used

            $message = \Swift_Message::newInstance()
                            ->setSubject('FMFI ANKETA -- nevhodný komentár')
                            ->setFrom($sender)
                            ->setTo($to)
                            ->setBody($body);
            $this->get('mailer')->send($message);

            $msg = $this->get('translator')->trans('statistics.controller.report_spracujeme');
            $session = $this->get('session');
            $session->getFlashBag()->add('success', $msg);

            return new RedirectResponse($section->getStatisticsPath());
        }
        else {
            return $this->render('AnketaBundle:Statistics:flagForm.html.twig', array(
                'section' => $section,
                'answer_id' => $answer_id,
                'comment_body' => $comment,
            ));
        }


    }

}
