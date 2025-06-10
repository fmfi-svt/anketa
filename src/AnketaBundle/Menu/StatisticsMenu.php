<?php
/**
 * @copyright Copyright (c) 2012 The FMFI Anketa authors (see AUTHORS).
 * Use of this source code is governed by a license that can be
 * found in the LICENSE file in the project root directory.
 *
 * @package    Anketa
 * @subpackage Anketa__Menu
 */

namespace AnketaBundle\Menu;

use Symfony\Component\DependencyInjection\ContainerInterface;
use AnketaBundle\Controller\StatisticsSection;

class StatisticsMenu
{
    /** @var ContainerInterface */
    protected $container;

    public function __construct(ContainerInterface $container) {
        $this->container = $container;
    }

    private function generateUrl($route, $parameters = array(), $absolute = false) {
        return $this->container->get('router')->generate($route, $parameters, $absolute);
    }

    private function buildMenu($activeItems = array()) {
        // TODO: to ze menu zavisi od $activeItems je dost hack
        // (vid HlasovanieController#buildMenu ze ako to ma vyzerat)
        $em = $this->container->get('doctrine.orm.entity_manager');
        $access = $this->container->get('anketa.access.statistics');
        $trans = $this->container->get('translator');
        // TODO: slugifier ako service
        $slugifier = new \AnketaBundle\Lib\Slugifier();

        $menu = array();
        $seasons = $em->getRepository('AnketaBundle:Season')->findBy(array(), array('ordering' => 'DESC'));
        foreach ($seasons as $season) {
            if (!$access->canSeeResults($season) && !$access->someoneCanSeeResults($season)) continue;
            // Add this season.
            $menu[$season->getId()] = $seasonItem = new MenuItem(
                $season->getDescription(),
                $this->generateUrl('statistics_season',
                    array('season_slug' => $season->getSlug())));
            if (!$access->canSeeResults($season)) continue;
            if (isset($activeItems[0]) && $activeItems[0] == $season->getId()) {
                $seasonItem->expanded = true;

                // Add "General questions" under this season.
                $seasonItem->children['general'] = new MenuItem(
                    $trans->trans('statistics.menu.vseobecne'),
                    $this->generateUrl('statistics_list_general',
                        array('season_slug' => $season->getSlug())));

                // Add "Study programmes" under this season.
                $studyProgramRepository = $em->getRepository('AnketaBundle:StudyProgram');
                if ($studyProgramRepository->countForSeason($season) > 0) {
                    $seasonItem->children['study_programs'] = $studyProgramsItem = new MenuItem(
                        $trans->trans('statistics.menu.studijne_programy'),
                        $this->generateUrl('statistics_list_programs',
                            array('season_slug' => $season->getSlug())));
                    if (isset($activeItems[1]) && $activeItems[1] == 'study_programs') {
                        $studyProgramsItem->expanded = true;
                        $studyPrograms = $studyProgramRepository->getAllWithAnswers($season);
                        foreach ($studyPrograms as $studyProgram) {
                            // Add this study program under "Study programmes".
                            $studyProgramSection = StatisticsSection::makeStudyProgramSection($this->container, $season, $studyProgram);
                            $studyProgramsItem->children[$studyProgram->getCode()] = new MenuItem(
                                $studyProgram->getCode(), $studyProgramSection->getStatisticsPath());
                        }
                    }
                }

                // Add "Subjects" under this season.
                $seasonItem->children['subjects'] = $subjectsItem = new MenuItem(
                    $trans->trans('statistics.menu.predmety'),
                    $this->generateUrl('statistics_list_subjects',
                        array('season_slug' => $season->getSlug())));
                if (isset($activeItems[1]) && $activeItems[1] == 'subjects') {
                    $subjectsByCategory = $em->getRepository('AnketaBundle:Subject')->getCategorizedSubjects($season);
                    foreach (array_keys($subjectsByCategory) as $category) {
                        $category = strval($category);
                        // Add this category under "Subjects".
                        $subjectsItem->children[$category] = $categoryItem = new MenuItem(
                            $category,
                            $this->generateUrl('statistics_list_subjects',
                                array('season_slug' => $season->getSlug())).'#'.$slugifier->slugify($category));
                        if (isset($activeItems[2]) && $activeItems[2] == $category) {
                            $subjectsItem->only_expanded = true;
                            $categoryItem->expanded = true;
                            foreach ($subjectsByCategory[$category] as $subject) {
                                // Add this subject under this category.
                                $subjectSection = StatisticsSection::makeSubjectSection($this->container, $season, $subject);
                                $categoryItem->children[$subject->getId()] = $subjectItem = new MenuItem(
                                    $subject->getName(), $subjectSection->getStatisticsPath());
                                if (isset($activeItems[3]) && $activeItems[3] == $subject->getId()) {
                                    $categoryItem->only_expanded = true;
                                    $teachersSubjects = $em->getRepository('AnketaBundle:TeachersSubjects')->findBy(array('subject' => $subject->getId(), 'season' => $season->getId()));
                                    foreach ($teachersSubjects as $teacherSubject) {
                                        // Add this teacher under this subject.
                                        $teacher = $teacherSubject->getTeacher();
                                        $teacherSection = StatisticsSection::makeSubjectTeacherSection($this->container, $season, $subject, $teacher);
                                        $subjectItem->children[$teacher->getId()] = $teacherItem = new MenuItem(
                                            $teacher->getFormattedName(), $teacherSection->getStatisticsPath());
                                        if ($teacherSubject->getLecturer()) $teacherItem->lecturer = true;
                                        if ($teacherSubject->getTrainer()) $teacherItem->trainer = true;
                                    }
                                }
                            }
                        }
                    }
                }

                // Add "All Comments" under this season
                $seasonItem->children['all_responses'] = new MenuItem(
                    $trans->trans('statistics.menu.vsetky_komentare'),
                    $this->generateUrl('responses_list',
                        array('season_slug' => $season->getSlug())));

                // Add "My subjects" under this season.
                if ($access->hasOwnSubjects($season)) {
                    $seasonItem->children['my_subjects'] = new MenuItem(
                        $trans->trans('statistics.menu.moje_predmety'),
                        $this->generateUrl('statistics_list_my_subjects',
                            array('season_slug' => $season->getSlug())));
                }

                // Add "My comments" under this section.
                if ($access->hasOwnResponses($season)) {
                    $seasonItem->children['my_comments'] = new MenuItem(
                        $trans->trans('statistics.menu.moje_komentare'),
                        $this->generateUrl('response',
                            array('season_slug' => $season->getSlug())));
                }

                // Add "My reports" under this season.
                if ($access->hasReports()) {
                    $seasonItem->children['my_reports'] = new MenuItem(
                        $trans->trans('statistics.menu.moje_reporty'),
                        $this->generateUrl('reports_my_reports',
                            array('season_slug' => $season->getSlug())));
                }

                // Add "Official statement" under this season.
                if ($season->getOfficialStatement()) {
                    $seasonItem->children['official_statement'] = new MenuItem(
                        $trans->trans('statistics.menu.stanovisko_vedenia'),
                        $this->generateUrl('statistics_statement',
                            array('season_slug' => $season->getSlug())));
                }

                // Add "Stats" under this season.
                if ($access->isSuperAdmin()) {
                    $seasonItem->children['stats'] = new MenuItem(
                        $trans->trans('statistics.menu.statistiky'),
                        $this->generateUrl('statistics_stats',
                            array('season_slug' => $season->getSlug())));
                }
            }
        }

        return $menu;
    }

    public function render($activeItems = array()) {
        $templateParams = array('menu' => $this->buildMenu($activeItems));

        $activeTail = null;
        $current = &$templateParams['menu'];
        foreach ($activeItems as $item) {
            if (!isset($current[$item])) {
                $activeTail = null;
                break;
            }
            $activeTail = $current[$item];
            $current[$item]->expanded = true;
            $current = &$current[$item]->children;
        }
        if ($activeTail) {
            $activeTail->active = true;
        }

        return $this->container->get('templating')->render('AnketaBundle::menu.html.twig',
                                                           $templateParams);
    }

}
