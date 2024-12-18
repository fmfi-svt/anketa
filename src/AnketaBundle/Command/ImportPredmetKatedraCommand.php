<?php

/**
 * @copyright Copyright (c) 2012 The FMFI Anketa authors (see AUTHORS).
 * Use of this source code is governed by a license that can be
 * found in the LICENSE file in the project root directory.
 *
 * @package    Anketa
 * @author     Martin Sucha <anty.sk+svt@gmail.com>
 */

namespace AnketaBundle\Command;

use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use AnketaBundle\Lib\NativeCSVTableReader;

/**
 * Class functioning as command/task for importing subject-department relation.
 *
 * @package    Anketa
 * @author     Martin Sucha <anty.sk+svt@gmail.com>
 */
class ImportPredmetKatedraCommand extends AbstractImportCommand {

    protected function configure() {
        parent::configure();

        $this
                ->setName('anketa:import:predmet-katedra')
                ->setDescription('Importuj priradenie predmet-katedra z textaku')
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

        $file = $this->openFile($input);
        $season = $this->getSeason($input);

        $subjectIdentification = $this->getContainer()->get('anketa.subject_identification');
        $tableReader = new NativeCSVTableReader($file);

        $conn = $this->getContainer()->get('database_connection');

        $conn->beginTransaction();

        $insertSubjectSeason = $conn->prepare("
                    INSERT INTO SubjectSeason (subject_id, season_id)
                    SELECT s.id, :season FROM Subject s WHERE s.slug = :subject_slug
                    ON DUPLICATE KEY UPDATE subject_id=subject_id
                    ");

        $insertSubjectSeasonDepartment = $conn->prepare("
                    INSERT INTO SubjectSeasonDepartment (subjectSeason_id, department_id)
                    SELECT ss.id, d.id FROM Subject s, SubjectSeason ss, Department d
                    WHERE ss.season_id = :season_id AND ss.subject_id = s.id AND s.slug = :subject_slug AND d.code = :department_code
                    ON DUPLICATE KEY UPDATE subjectSeason_id = subjectSeason_id
            ");

        $rows = 0;
        try {
            while (($row = $tableReader->readAssocRow()) !== false) {
                $rows++;
                
                $subjectCode = $row['Skratka'];
                $stredisko = $row['Stredisko'];
                $subjectName = $row['Názov'];

                $props = $subjectIdentification->identify($subjectCode, $subjectName);
                $kod = $props['code'];
                $nazov = $props['name'];
                $slug = $props['slug'];

                $insertSubjectSeason->bindValue('subject_slug', $slug);
                $insertSubjectSeason->bindValue('season', $season->getId());
                $insertSubjectSeason->execute();

                $insertSubjectSeasonDepartment->bindValue('season_id', $season->getId());
                $insertSubjectSeasonDepartment->bindValue('subject_slug', $slug);
                $insertSubjectSeasonDepartment->bindValue('department_code', $stredisko);
                $insertSubjectSeasonDepartment->execute();
            }
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }

        $conn->commit();
        $output->writeln("Processed ".$rows." rows from ".$input->getArgument('file'));
        fclose($file);
    }

}
