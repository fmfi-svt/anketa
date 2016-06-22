<?php
/**
 * This file contains user source interface
 *
 * @copyright Copyright (c) 2011-2012 The FMFI Anketa authors (see AUTHORS).
 * Use of this source code is governed by a license that can be
 * found in the LICENSE file in the project root directory.
 *
 * @package    Anketa
 * @subpackage Security
 * @author     Martin Sucha <anty.sk+svt@gmail.com>
 */

namespace AnketaBundle\Security;

use Doctrine\ORM\EntityManager;
use AnketaBundle\Entity\User;
use AnketaBundle\Entity\UserSeason;
use AnketaBundle\Entity\UsersSubjects;
use AnketaBundle\Entity\Subject;
use AnketaBundle\Integration\AISRetriever;
use AnketaBundle\Entity\Role;
use AnketaBundle\Lib\SubjectIdentificationInterface;
use AnketaBundle\Lib\SubjectIdentification;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Log\LoggerInterface;

class AISUserSource implements UserSourceInterface
{

    /** @var EntityManager */
    private $em;

    /** @var Connection */
    private $dbConn;

    /** @var AISRetriever */
    private $aisRetriever;

    /** @var LoggerInterface */
    private $logger;

    /** @var SubjectIdentificationInterface */
    private $subjectIdentification;

    /** @var string */
    private $allowedOrgUnit;

    public function __construct(Connection $dbConn, EntityManager $em, AISRetriever $aisRetriever,
            SubjectIdentificationInterface $subjectIdentification, $allowedOrgUnit, $checkOrgUnit,
            LoggerInterface $logger = null)
    {
        $this->dbConn = $dbConn;
        $this->em = $em;
        $this->aisRetriever = $aisRetriever;
        $this->logger = $logger;
        $this->subjectIdentification = $subjectIdentification;
        $this->allowedOrgUnit = ($checkOrgUnit ? $allowedOrgUnit : null);
    }

    public function load(UserSeason $userSeason, array $want)
    {
        if (isset($want['displayName'])) {
            throw new \Exception("AISUserSource currently doesn't support displayName");
        }

        if (isset($want['subjects']) || isset($want['isStudentThisSeason']) || isset($want['isStudentAtAnyTime'])) {
            $semestre = null;
            if (isset($want['subjects'])) {
                $semestre = $userSeason->getSeason()->getAisSemesterList();
                if (empty($semestre)) {
                    throw new \Exception("Sezona nema nastavene aisSemesters");
                }
            }

            $result = $this->aisRetriever->getResult($this->allowedOrgUnit, $semestre);

            if ($result['is_student']) {
                if (isset($want['subjects'])) {
                    $this->loadSubjects($userSeason, $result['subjects']);
                }

                if (isset($want['isStudentThisSeason'])) {
                    $userSeason->setIsStudent(true);
                }

                if (isset($want['isStudentAtAnyTime'])) {
                    $userSeason->getUser()->addRole($this->em->getRepository('AnketaBundle:Role')
                            ->findOrCreateRole('ROLE_STUDENT_AT_ANY_TIME'));
                }
            }
        }
    }

    /**
     * Load subject entities associated with this user
     */
    private function loadSubjects(UserSeason $userSeason, $aisPredmety)
    {
        $slugy = array();

        foreach ($aisPredmety as $aisPredmet) {
            $props = $this->subjectIdentification->identify($aisPredmet['skratka'], $aisPredmet['nazov']);

            // Ignorujme duplicitne predmety
            if (in_array($props['slug'], $slugy)) {
                continue;
            }
            $slugy[] = $props['slug'];

            $this->dbConn->beginTransaction();

            // vytvorime subject v DB ak neexistuje
            // pouzijeme INSERT ON DUPLICATE KEY UPDATE
            // aby sme nedostavali vynimky pri raceoch
            // Pri tejto query sa id zaznamu pri update nemeni.
            // (Aj ked to tak moze vyzerat.)
            $stmt = $this->dbConn->prepare("INSERT INTO Subject (code, name, slug)
                                            VALUES (:code, :name, :slug)
                                            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), slug=slug");
            $stmt->bindValue('code', $props['code']);
            $stmt->bindValue('name', $props['name']);
            $stmt->bindValue('slug', $props['slug']);
            if (!$stmt->execute()) {
                throw new \Exception("Nepodarilo sa pridať predmet do DB");
            }
            $stmt = null;
            $subjectId = $this->dbConn->lastInsertId();



            // Vytvorime studijny program v DB ak neexistuje
            // podobne ako predmet vyssie
            $stmt = $this->dbConn->prepare("INSERT INTO StudyProgram (code, name, slug)
                                            VALUES (:code, :name, :slug)
                                            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), code=code");
            $stmt->bindValue('code', $aisPredmet['studijnyProgram']['skratka']);
            $stmt->bindValue('name', $aisPredmet['studijnyProgram']['nazov']);
            $stmt->bindValue('slug', $this->generateSlug($aisPredmet['studijnyProgram']['skratka']));
            if (!$stmt->execute()) {
                throw new \Exception("Nepodarilo sa pridať študijný program do DB");
            }
            $stmt = null;
            $studyProgramId = $this->dbConn->lastInsertId();


            $stmt = $this->dbConn->prepare("INSERT INTO UsersSubjects (user_id, subject_id, season_id, studyProgram_id, studyYear)
                                            VALUES (:user_id, :subject_id, :season_id, :studyProgram_id, :studyYear)
                                            ON DUPLICATE KEY UPDATE subject_id=subject_id");
            $stmt->bindValue('user_id', $userSeason->getUser()->getId());
            $stmt->bindValue('subject_id', $subjectId);
            $stmt->bindValue('season_id', $userSeason->getSeason()->getId());
            $stmt->bindValue('studyProgram_id', $studyProgramId);
            $stmt->bindValue('studyYear', $aisPredmet['rokStudia']);
            if (!$stmt->execute()) {
                throw new \Exception("Nepodarilo sa pridať väzbu študent-predmet do DB");
            }
            $stmt = null;

            $this->dbConn->commit();
        }
    }

    /**
     * @todo presunut do samostatnej triedy a spravit lepsie
     *   (uz sa to pouziva len na studijne programy)
     */
    private function generateSlug($slug)
    {
        $slug = preg_replace('@[^a-zA-Z0-9_]@', '-', $slug);
        $slug = preg_replace('@-+@', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }

}
