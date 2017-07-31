<?php
/**
 * @copyright Copyright (c) 2011 The FMFI Anketa authors (see AUTHORS).
 * Use of this source code is governed by a license that can be
 * found in the LICENSE file in the project root directory.
 *
 * @package    Anketa
 * @subpackage Anketa__Entity__Repository
 * @author     Jakub Markoš <jakub.markos@gmail.com>
 */

namespace AnketaBundle\Entity;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use PDO;

class UserRepository extends EntityRepository {

    public function findOneWithRolesByLogin($username)
    {
        $q = $this->createQueryBuilder('u')
                ->leftJoin('u.roles', 'r')
                ->where('u.login = :username')
                ->getQuery();
        $q->setParameter('username', $username);

        $result = $q->execute();

        if (count($result) > 1) {
            throw new NonUniqueResultException;
        }
        return array_shift($result);
    }

    public function findUsersWithAnyRole($roles)
    {
        $qb = $this->createQueryBuilder('u');
        $qb = $qb->leftJoin('u.roles', 'r')
            ->where($qb->expr()->in('r.name', $roles))
            ->getQuery();

        return $qb->execute();
    }

    public function anonymizeAnswersByUser(User $user, Season $season) {
        $em = $this->getEntityManager();
        $conn = $em->getConnection();
        $conn->beginTransaction();
        try {

            // Mozno nevkladat nove riadky, ale rovno scitavat na sposob
            // ON DUPLICATE KEY UPDATE count=count+1
            // (neslo to priamo spravit, lebo problem s NULL v unique kluci)
            $insertVoteSummary = $conn->prepare("
                INSERT INTO SectionVoteSummary (category_id, subject_id, teacher_id, season_id, count)
                SELECT DISTINCT q.category_id, a.subject_id, a.teacher_id, :season_id, 1
                FROM Answer a, Question q
                WHERE a.author_id = :user_id AND a.season_id = :season_id
                AND q.id = a.question_id
                AND NOT(a.option_id IS NULL AND comment IS NULL)
            ");

            $insertVoteSummary->bindValue('season_id', $season->getId(), PDO::PARAM_INT);
            $insertVoteSummary->bindValue('user_id', $user->getId(), PDO::PARAM_INT);
            if (!$insertVoteSummary->execute()) {
                throw new \Exception("Failed to create voting summaries!");
            }

            $q = $em->createQueryBuilder()
                    ->update('AnketaBundle\Entity\Answer', 'a')
                    ->set('a.author', ':nobody')
                    ->where('a.author = :user AND a.season = :season')
                    ->getQuery();
            $q->setParameters(array(
                'nobody' => null,
                'user' => $user,
                'season' => $season
             ));
            $result = $q->execute();

            $conn->commit();
            return $result;
            
        } catch (\Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }

    public function anonymizeAllAnswersAndCreateSummaries(Season $season) {
        $em = $this->getEntityManager();
        $conn = $em->getConnection();
        try {
            $conn->beginTransaction();

            $insertVoteSubSummary = $conn->prepare("
                INSERT INTO SectionVoteSummary (category_id, subject_id, teacher_id, season_id, count)
                SELECT q.category_id, a.subject_id, a.teacher_id, :season_id, COUNT(DISTINCT a.author_id)
                FROM Answer a, Question q
                WHERE a.season_id = :season_id
                AND q.id = a.question_id
                AND a.author_id IS NOT NULL
                AND NOT(a.option_id IS NULL AND comment IS NULL)
                GROUP BY q.category_id, a.subject_id, a.teacher_id
            ");

            $insertVoteSubSummary->bindValue('season_id', $season->getId(), PDO::PARAM_INT);
            if (!$insertVoteSubSummary->execute()) {
                throw new \Exception("Failed to create voting summaries!");
            }

            // vyselectujem sucty, pre kazdy zmazem relevantne riadky a pridam jeden novy

            $computeVoteSummary = $conn->prepare("
                SELECT teacher_id, subject_id, category_id, SUM(count) as count
                FROM SectionVoteSummary
                WHERE season_id = :season_id
                GROUP BY teacher_id, subject_id, category_id
            ");
            $computeVoteSummary->bindValue('season_id', $season->getId(), PDO::PARAM_INT);
            if (!$computeVoteSummary->execute()) {
                throw new \Exception("Failed to compute total voting summaries!");
            }
            $summaries = $computeVoteSummary->fetchAll();

            $deleteVoteSummary = $conn->prepare("
                DELETE FROM SectionVoteSummary
                WHERE season_id = :season_id
            ");
            $deleteVoteSummary->bindValue('season_id', $season->getId(), PDO::PARAM_INT);
            if (!$deleteVoteSummary->execute()) {
                throw new \Exception("Failed to remove subtotal voting summaries!");
            }

            // This could be done faster by using just one insert query.
            // Since this method will be called very seldom,
            // the speed gain doesn't convince me to write ugly complicated code
            // which would achieve it.
            $insertVoteSummary = $conn->prepare("
                INSERT INTO SectionVoteSummary (category_id, subject_id, teacher_id, season_id, count)
                VALUES (:category_id, :subject_id, :teacher_id, :season_id, :count)
            ");
            foreach ($summaries as $summary) {
                foreach ($summary as $name => $value) {
                    $insertVoteSummary->bindValue($name, $value, PDO::PARAM_INT);
                }
                $insertVoteSummary->bindValue('season_id', $season->getId(), PDO::PARAM_INT);
                if (!$insertVoteSummary->execute()) {
                    throw new \Exception("Failed to insert SectionVoteSummary!");
                }
            }

            $q = $em->createQueryBuilder()
                    ->update('AnketaBundle\Entity\Answer', 'a')
                    ->set('a.author', ':nobody')
                    ->where('a.season = :season')
                    ->getQuery();
            $q->setParameters(array(
                'nobody' => null,
                'season' => $season
             ));
            if (!$q->execute()) {
                throw new \Exception('Failed to anonymize answers!');
            }

            $conn->commit();
            
        } catch (\Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }

    public function getNumberOfVoters($season) {
        $em = $this->getEntityManager();
        $query = $em->createQuery("SELECT COUNT(us.id) as voters
                                   FROM AnketaBundle\Entity\UserSeason us
                                   WHERE us.isStudent = true
                                   AND us.participated = true
                                   AND us.season = :season");
        $query->setParameter('season', $season);
        $result = $query->getResult();
        return $result[0]['voters'];
    }

    /**
     * Pocet ludi co anonymizovali.
     * (Toto je korektne iba kym anketa bezi a neanonymizovali sme zvysnych.)
     */
    public function getNumberOfAnonymizations($season) {
        $em = $this->getEntityManager();
        $query = $em->createQuery("SELECT COUNT(us.id) as anon
                                   FROM AnketaBundle\Entity\UserSeason us
                                   WHERE us.isStudent = true
                                   AND us.participated = true
                                   AND us.finished = true
                                   AND us.season = :season");
        $query->setParameter('season', $season);
        $result = $query->getResult();
        return $result[0]['anon'];
    }

        public function getTeachersForSubject($subject, $season) {
        $dql = 'SELECT t FROM AnketaBundle\Entity\User t, ' .
                  'AnketaBundle\Entity\TeachersSubjects ts WHERE t = ts.teacher ' .
                  ' AND ts.subject = :subject ' .
                  ' AND ts.season = :season ' .
                  ' ORDER BY t.familyName, t.givenName';

        $teachers = $this->getEntityManager()
                         ->createQuery($dql)->execute(array('subject' => $subject,
                             'season' => $season));
        return $teachers;
    }

    public function getTeachersForSubjectWithAnswers($subject, $season) {
        $dql = 'SELECT t FROM AnketaBundle\Entity\User t, ' .
                  'AnketaBundle\Entity\TeachersSubjects ts, ' .
                  'AnketaBundle\Entity\Answer a ' .
                  'WHERE t = ts.teacher ' .
                  // TODO vyhodit riadok nizsie ked uz nebudu v DB "ucitelia"
                  // typu .ucitelN
                  ' AND t.displayName IS NOT NULL ' .
                  ' AND ts.subject = :subject ' .
                  ' AND ts.season = :season ' .
                  ' AND a.subject = :subject ' .
                  ' AND a.teacher = t ' .
                  ' AND a.season = :season ' .
                  ' AND a.option is not null ' .
                  ' ORDER BY t.familyName, t.givenName';

        $teachers = $this->getEntityManager()
                         ->createQuery($dql)->execute(array('subject' => $subject,
                             'season' => $season));
        return $teachers;
    }

    public function getTeachersForStudyProgramme($studyProgramme, $season) {
        $dql = 'SELECT DISTINCT t FROM AnketaBundle\Entity\UsersSubjects us, ' .
                'AnketaBundle\Entity\Subject s, ' .
                'AnketaBundle\Entity\TeachersSubjects ts, ' .
                'AnketaBundle\Entity\User t, ' .
                'AnketaBundle\Entity\Answer a ' .
                'WHERE us.subject = s ' .
                'AND ts.subject = s ' .
                'AND ts.teacher = t ' .
                'AND a.teacher = t ' .
                'AND a.season = :season ' .
                'AND us.season = :season ' .
                'AND ts.season = :season ' .
                'AND us.studyProgram = :studyProgramme ' .
                'ORDER BY t.familyName';
        $teachers = $this->getEntityManager()
                        ->createQuery($dql)->execute(array('studyProgramme' => $studyProgramme, 'season' => $season));
        return $teachers;
    }

    public function getTeachersForDepartment($department, $season) {
        $dql = 'SELECT DISTINCT t FROM AnketaBundle\Entity\Department d, ' .
                'AnketaBundle\Entity\User t, ' .
                'AnketaBundle\Entity\Answer a ' .
                'WHERE d = t.department ' .
                'AND a.teacher = t ' .
                'AND a.season = :season ' .
                'AND d = :department ' .
                'ORDER BY t.familyName';
        $teachers = $this->getEntityManager()
                        ->createQuery($dql)->execute(array('department' => $department, 'season' => $season));
        return $teachers;
    }

    public function getUsersForSeason($season) {
        $dql = 'SELECT DISTINCT u FROM AnketaBundle\Entity\User u, ' .
                'AnketaBundle\Entity\UserSeason us ' .
                'WHERE us.season = :season ' .
                'AND u = us.user ';
        $users = $this->getEntityManager()
                        ->createQuery($dql)->execute(array('season' => $season));
        return $users;
    }

    public function getUserDepartment(User $user, Season $season){
        $dql = 'SELECT d FROM AnketaBundle\Entity\UserSeason us, ' .
                'AnketaBundle\Entity\Department d ' .
                'WHERE us.season = :season ' .
                'AND us.user = :user ' .
                'AND us.department = d';
        $department = $this->getEntityManager()
                   ->createQuery($dql)
                   ->setParameter('season', $season)
                   ->setParameter('user', $user)
                   ->getOneOrNullResult();
        if($department === null) {
            $department = $user->getDepartment();
            if ($department === null) throw new \Exception("User ".$user->getLogin()." nema department");
        }
        return $department;
    }

}
