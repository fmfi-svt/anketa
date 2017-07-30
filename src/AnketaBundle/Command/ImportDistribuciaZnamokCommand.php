<?php

/**
 * @copyright Copyright (c) 2012 The FMFI Anketa authors (see AUTHORS).
 * Use of this source code is governed by a license that can be
 * found in the LICENSE file in the project root directory.
 *
 * @package    Anketa
 * @author     Dusan Plavak <dusan.plavak@gmail.com>
 */

namespace AnketaBundle\Command;

use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use DOMDocument;

/**
 * Class functioning as command/task for importing distribution of grades.
 *
 * @package    Anketa
 * @author     Dusan Plavak <dusan.plavak@gmail.com>
 */
class ImportDistribuciaZnamokCommand extends AbstractImportCommand {

    protected function configure() {
        parent::configure();

        $this
                ->setName('anketa:import:distribucia-znamok')
                ->setDescription('Importuj distribuciu znamok pre predmety z xml exportu.')
                ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Whether to dump SQL instead of executing')
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

        $xml = simplexml_load_file($input->getArgument('file'));

        $subjectsGrades = array();
        $gradesArray = array('A', 'B', 'C', 'D', 'E', 'FX');

        foreach($xml->detail as $detail){
            $code = (string) $detail->skratkaPredmet;
            $grade = (string) $detail->hodnotenie;

            if(!in_array($grade, $gradesArray)) {
                $output->writeln("V predmete ".$code." sa vyskytla neznama znamka ".$grade);
                continue;
            }
            if(!isset($subjectsGrades[$code])) $subjectsGrades[$code] = array('A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0, 'FX' => 0);
            $subjectsGrades[$code][$grade]++;
        }
        if (preg_match('/^\d+$/', $input->getOption('season'))) {
            $season_id = intval($input->getOption('season'));
        }
        else {
            $season_id = $this->getSeason($input)->getId();
        }

        $conn = $this->getContainer()->get('database_connection');

        $conn->beginTransaction();

        $dumpSQL = $input->getOption('dump-sql');

        if (!$dumpSQL) {
            $insertSubjectGradesDistribution = $conn->prepare("
                    UPDATE SubjectSeason ss, Subject s SET ss.aCount = :As, ss.bCount = :Bs,
                                                           ss.cCount = :Cs, ss.dCount = :Ds,
                                                           ss.eCount = :Es, ss.fxCount = :FXs
                    WHERE  s.id = ss.subject_id AND ss.season_id = :season AND s.code = :code");
        }
        else {
            $insertTemplate = "UPDATE SubjectSeason ss, Subject s SET ss.aCount = %d, " .
                              "        ss.bCount = %d, ss.cCount = %d, ss.dCount = %d," .
                              "        ss.eCount = %d, ss.fxCount = %d " .
                              " WHERE  s.id = ss.subject_id AND ss.season_id = :season AND s.code = :code";
        }

        try {
            if ($dumpSQL) {
                foreach ($subjectsGrades as $code => $grades) {
                    $output->writeln(sprintf($insertTemplate, $grades['A'], $grades['B'], $grades['C'], 
                                             $grades['D'], $grades['E'], $grades['FX'], $season_id, $conn->quote($code)));
                }
            }
            else {
                foreach ($subjectsGrades as $code => $grades) {
                    $insertSubjectGradesDistribution->bindValue('As', $grades['A']);
                    $insertSubjectGradesDistribution->bindValue('Bs', $grades['B']);
                    $insertSubjectGradesDistribution->bindValue('Cs', $grades['C']);
                    $insertSubjectGradesDistribution->bindValue('Ds', $grades['D']);
                    $insertSubjectGradesDistribution->bindValue('Es', $grades['E']);
                    $insertSubjectGradesDistribution->bindValue('FXs', $grades['FX']);
                    $insertSubjectGradesDistribution->bindValue('code', $code);
                    $insertSubjectGradesDistribution->bindValue('season', $season_id);
                    $insertSubjectGradesDistribution->execute();
                }
            }
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }

        $conn->commit();
    }

}
