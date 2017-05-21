<?php

/**
 * @copyright Copyright (c) 2012 The FMFI Anketa authors (see AUTHORS).
 * Use of this source code is governed by a license that can be
 * found in the LICENSE file in the project root directory.
 *
 * @package    Anketa
 * @author     Jakub Marek <jakub.marek@gmail.com>
 */

namespace AnketaBundle\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use AnketaBundle\Lib\NativeCSVTableReader;
use AnketaBundle\Lib\FixedWidthTableReader;

/**
 * Class functioning as command/task for importing teachers, subjects,
 * and relationship between teachers and subjects from text file.
 *
 * @package    Anketa
 * @author     Jakub Marek <jakub.marek@gmail.com>
 */
class ImportUcitelPredmetCommand extends AbstractImportCommand {

    protected function configure() {
        parent::configure();

        $this
                ->setName('anketa:import:ucitel-predmet')
                ->setDescription('Importuj ucitelov predmety z textaku')
                ->addSeasonOption()
        ;
    }

    /**
     * Executes the current command.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @return integer 0 if everything went fine, or an error code
     *
     * @throws \LogicException When this abstract class is not implemented
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $subjectIdentification = $this->getContainer()->get('anketa.subject_identification');

        $season = $this->getSeason($input);
        $file = $this->openFile($input);

        $tableReader = new NativeCSVTableReader($file);

        $conn = $this->getContainer()->get('database_connection');

        $conn->beginTransaction();

        $insertUser = $conn->prepare("
                    INSERT INTO User ( givenName, familyName, displayName, login)
                    VALUES (:givenName, :familyName, :displayName, :login)
                    ON DUPLICATE KEY UPDATE login=login");

        $insertUserSeason = $conn->prepare("
                    INSERT INTO UserSeason ( user_id, season_id, isTeacher, isStudent, loadedFromAis)
                    SELECT a.id, :seasonId, 1, 0, 0
                    FROM User a
                    WHERE a.login = :login
                    ON DUPLICATE KEY UPDATE isTeacher=1");

        $insertSubject = $conn->prepare("
                    INSERT INTO Subject (code, name, slug)
                    VALUES (:code, :name, :slug)
                    ON DUPLICATE KEY UPDATE slug=slug");

        $insertTeacherSubject = $conn->prepare("
                    INSERT INTO TeachersSubjects (teacher_id, subject_id, season_id, lecturer, trainer)
                    SELECT a.id, b.id, :season, :isLecturer, :isTrainer
                    FROM User a, Subject b
                    WHERE a.login = :login and b.slug = :slug
                    ON DUPLICATE KEY UPDATE lecturer=GREATEST(VALUES(lecturer), TeachersSubjects.lecturer),
                        trainer=GREATEST(VALUES(trainer), TeachersSubjects.trainer)");

        $rows = 0;
        $rowsSuccess = 0;
        $rowsIncomplete = 0;
        $rowsUnknown = 0;
        try {
            while (($row = $tableReader->readAssocRow()) !== false) {
                $rows++;

                $id = $row['Predmet id'];
                $aisDlhyKod = $row['Plná skratka'];
                $aisStredisko = $row['Stredisko'];
                $aisKratkyKod = $row['Skratka'];
                $aisPopisRokVzniku = $row['Rok vzniku'];
                $aisNazov = $row['Názov'];
                $semester = $row['Semester'];
                $hodnost = $row['Typ vyučujúceho'];
                $plneMeno = $row['Plné meno'];
                $priezvisko = $row['Priezvisko'];
                $meno = $row['Meno'];
                $login = $row['Login'];

                if (strlen($aisNazov) == 0) {
                    $output->writeln($aisDlhyKod . ': Chýba meno predmetu');
                    $rowsIncomplete++;
                    continue;
                }
                if (strlen($plneMeno) == 0) {
                    $output->writeln($aisDlhyKod . ': Chýba meno vyučujúceho '.$meno .' '.$priezvisko);
                    $rowsIncomplete++;
                    continue;
                }
                if (strlen($login) == 0) {
                    $output->writeln($aisDlhyKod . ': Chýba login vyučujúceho '.$plneMeno);
                    $rowsIncomplete++;
                    continue;
                }

                $aisRokVzniku = substr($aisPopisRokVzniku, 2, 2);
                // TODO: Hmm, je toto spravne? (i.e. aj pre CSV, kde je priamo dlhy kod?)
                $aisDlhyKod = $aisStredisko . '/' . $aisKratkyKod . '/' . $aisRokVzniku;

                $props = $subjectIdentification->identify($aisDlhyKod, $aisNazov);
                $kod = $props['code'];
                $nazov = $props['name'];
                $slug = $props['slug'];

                $prednasajuci = 0;
                $cviciaci = 0;

                if ($hodnost == 'P' || $hodnost == 'H' || $hodnost == 'S') {
                    $prednasajuci = 1;
                } else if ($hodnost == 'C' || $hodnost == 'V') {
                    $cviciaci = 1;
                } else {
                    $output->writeln($aisDlhyKod . ': neznamy typ vyučujúceho \'' . $hodnost . '\'');
                    $rowsUnknown++;
                    continue;
                }

                $insertUser->bindValue('displayName', $plneMeno);
                $insertUser->bindValue('givenName', $meno);
                $insertUser->bindValue('familyName', $priezvisko);
                $insertUser->bindValue('login', $login);
                $insertUser->execute();

                $insertUserSeason->bindValue('seasonId', $season->getId());
                $insertUserSeason->bindValue('login', $login);
                $insertUserSeason->execute();

                $insertSubject->bindValue('code', $kod);
                $insertSubject->bindValue('name', $nazov);
                $insertSubject->bindValue('slug', $slug);
                $insertSubject->execute();

                $insertTeacherSubject->bindValue('slug', $slug);
                $insertTeacherSubject->bindValue('login', $login);
                $insertTeacherSubject->bindValue('season', $season->getId());
                $insertTeacherSubject->bindValue('isLecturer', $prednasajuci);
                $insertTeacherSubject->bindValue('isTrainer', $cviciaci);
                $insertTeacherSubject->execute();

                $rowsSuccess++;
            }
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }

        $conn->commit();
        $output->writeln("Processed ".$rows." rows from ".$input->getArgument('file'));
        $output->writeln("Successful rows: ".$rowsSuccess.", incomplete rows: ".$rowsIncomplete.", unknown rows: ".$rowsUnknown);
        fclose($file);
    }

}
