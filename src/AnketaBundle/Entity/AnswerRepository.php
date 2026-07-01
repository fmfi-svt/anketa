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

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\EntityRepository;
use AnketaBundle\Entity\Subject;
use AnketaBundle\Entity\User;

/**
 * Repository class for Answer Entity
 */

class AnswerRepository extends EntityRepository {

    /**
     *
     * @param array $questions array of questions
     * @param User $user current user
     * @param Season $season active season
     * @param Subject $subject
     * @return array array of answers, indexed with question ids
     */
    public function getAnswersByCriteria(
            array $questions, User $user, Season $season,
            Subject $subject = null, User $teacher = null,
            StudyProgram $studyProgramme = null)
    {
        // odpoved je jednoznacne identifikovana autorom, id otazky, id predmetu
        // mozno by bolo fajn vytvorit nad tym teda unique index
        $result = array();
        $answerRep = $this->getEntityManager()->getRepository('AnketaBundle\Entity\Answer');
        $criteria = array(
            'author' => $user->getId(),
            'season' => $season->getId()
         );
        if ($subject != null) {
            $criteria['subject'] = $subject->getId();
        }
        if ($teacher != null) {
            $criteria['teacher'] = $teacher->getId();
        }
        if ($studyProgramme != null) {
            $criteria['studyProgram'] = $studyProgramme->getId();
        }
        foreach ($questions AS $question) {
            $criteria['question'] = $question->getId();
            $answer = $answerRep->findOneBy($criteria);
            $result[$question->getId()] = $answer;
        }
        return $result;
    }

    /**
     *
     * @param User $user
     * @param Season $season
     * @return integer number of user answers (not counting answers to subjects
     * not attended)
     */
    public function getAnswersCount($user, $season) {
        $em = $this->getEntityManager();
        $query = $em->createQuery('SELECT COUNT(a.id)
                                   FROM AnketaBundle\Entity\Answer a
                                   WHERE a.author = :user AND
                                         a.season = :season AND
                                         ((a.subject IS NULL) OR (a.attended = true))');
        $query->setParameter('user', $user);
        $query->setParameter('season', $season);
        return $query->getSingleScalarResult();
    }


    public function getAverageEvaluationForTeacher($teacher, $season) {
        $dql = 'SELECT AVG(o.evaluation), COUNT(a.option) FROM ' .
                'AnketaBundle\Entity\Answer a, AnketaBundle\Entity\Question q, AnketaBundle\Entity\Option o ' .
                'WHERE ' .
                'a.teacher = :teacher ' .
                'AND a.question = q.id AND q.isTeacherEvaluation = 1 ' .
                'AND a.option = o.id ' .
                'AND a.season = :season ';
        $priemer = $this->getEntityManager()
                        ->createQuery($dql)->execute(array('teacher' => $teacher, 'season' => $season));
        return $priemer[0];
    }

    public function getAverageEvaluationForSubject($subject, $season) {
        $dql = 'SELECT AVG(o.evaluation), COUNT(a.option) FROM ' .
                'AnketaBundle\Entity\Answer a, AnketaBundle\Entity\Question q, AnketaBundle\Entity\Option o ' .
                'WHERE ' .
                'a.subject = :subject ' .
                'AND a.question = q.id AND q.isSubjectEvaluation = 1 ' .
                'AND a.option = o.id ' .
                'AND a.season = :season ';
        $priemer = $this->getEntityManager()
                        ->createQuery($dql)->execute(array('subject' => $subject, 'season' => $season));
        return $priemer[0];
    }

    public function getMostRecentAverageEvaluations($subjectCodes) {
        $codes = array_fill(0, count($subjectCodes), '?');
        $codes = implode($codes,',');
        // pre kazdy predmet zistime priemer celkoveho hodnotenia
        // z najnovsej sezony v ktorej sa vyskytol, vysledky su public
        // a ma aspon jedno celkove ohodnotenie
        $sql = 'SELECT su.id, su.code, su.slug as subject_slug, AVG(o.evaluation) as average, '.
               'COUNT(a.id) as votes, s.slug as season_slug '.
               'FROM Answer a, Choice o, Question q, Season s, Subject su '.
               'WHERE su.code IN ('.$codes.') '.
               'AND a.subject_id = su.id '.
               'AND a.question_id = q.id AND q.isSubjectEvaluation = 1 '.
               'AND a.option_id = o.id '.
               'AND a.season_id = s.id '.
               'AND s.ordering = ( '.
                // tento subselect vyberie najnovsiu sezonu s verejnymi vysledkami
                // v ktorej sa nachadza predmet 'su' a je k nemu aspon jedno hodnotenie
                    'SELECT MAX(s.ordering) '.
                    'FROM Season s, SubjectSeason ss, Answer a, Question q '.
                    'WHERE s.id = ss.season_id '.
                    'AND ss.subject_id = su.id '.
                    'AND a.subject_id = su.id '.
                    // toto je pre candle API takze chceme to co vidi verejnost
                    'AND s.levelPublic >= '.Season::LEVEL_NUMBERS.' '.
                    'AND a.season_id = s.id '.
                    'AND a.question_id = q.id '.
                    'AND q.season_id = s.id '.
                    'AND q.isSubjectEvaluation = 1 '.
                    'GROUP BY ss.subject_id '.
                    'HAVING COUNT(a.id) > 0'.
               ') '.
               'GROUP BY su.id;';
        $query = $this->getEntityManager()->getConnection()->prepare($sql);
        $query->execute($subjectCodes);
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getSubjectsRatings() {
	$sql = 'SELECT code,name,slug,season_id,score '.
               'FROM Subject,Question, '.
               '(SELECT subject_id,Answer.question_id, '.
               'AVG(evaluation) AS score, '.
               'COUNT(evaluation) AS number '.
               'FROM Answer,Choice '.
               'WHERE Answer.option_id=Choice.id '.
               'GROUP BY subject_id,teacher_id,Answer.question_id) AS Summary '.
               'WHERE Summary.question_id=Question.id '.
               'AND Summary.subject_id=Subject.id '.
               'AND isSubjectEvaluation=1;';
        $query = $this->getEntityManager()->getConnection()->prepare($sql);
        $query->execute();
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }
}
