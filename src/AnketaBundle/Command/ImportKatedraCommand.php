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
use AnketaBundle\Lib\TableColumnResolver;

/**
 * Class functioning as command/task for importing departments.
 *
 * @package    Anketa
 * @author     Martin Sucha <anty.sk+svt@gmail.com>
 */
class ImportKatedraCommand extends AbstractImportCommand {

    protected function configure() {
        parent::configure();

        $this
                ->setName('anketa:import:katedra')
                ->setDescription('Importuj katedry z textaku')
                ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Importovať iba katedry pod touto OJ')
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
        $parentFilter = $input->getOption('parent');

        // nacitaj prve riadky, ktore nas nezaujimaju
        for ($i = 0;$i < 5; $i++) {
            fgets($file);
        }
        $tableReader = new NativeCSVTableReader($file);
        $tableResolver = new TableColumnResolver($tableReader);
        $tableResolver->mapColumnByTitle('OJ', 'code');
        $tableResolver->mapColumnByTitle('Nadriadená org. j.', 'parent');
        $tableResolver->mapColumnByTitle('Typ OJ', 'type');
        $tableResolver->mapColumnByTitle('Org. jednotka', 'name');
        $tableResolver->mapColumnByTitle('Homepage', 'homepage');

        $conn = $this->getContainer()->get('database_connection');

        $conn->beginTransaction();

        $insertDepartment = $conn->prepare("
                    INSERT INTO Department (code, name, homepage)
                    VALUES (:code, :name, :homepage)");

        // Pocitadla spracovanych zaznamov
        $rows = 0;
        $rowsSuccess = 0;
        $rowsNotDepartment = 0;
        $rowsFiltered = 0;
        $rowsFailed = 0;

        try {
            while (($row = $tableResolver->readRow()) !== false) {
                $rows++;
                $code = $row['code'];
                $parentOrgUnit = $row['parent'];
                $type = $row['type'];
                $name = $row['name'];
                $homepage = $row['homepage'];

                if ($type !== 'Kated' && $type !== 'Ústav') {
                    $rowsNotDepartment++;
                    continue;
                }
                if ($parentFilter !== null && $parentOrgUnit !== $parentFilter) {
                    $rowsFiltered++;
                    continue;
                }

                if ($homepage === 'http://' || $homepage === '') {
                    $homepage = null;
                }

                $insertDepartment->bindValue('code', $code);
                $insertDepartment->bindValue('name', $name);
                $insertDepartment->bindValue('homepage', $homepage);
                if ($insertDepartment->execute()) {
                    $rowsSuccess++;
                }
                else {
                    $rowsFailed++;
                }

            }
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }

        $conn->commit();
        fclose($file);

        $output->writeln("Processed ".$rows." rows.");
        $output->writeln("Successful rows: ".$rowsSuccess.", non-department rows skipped: ".$rowsNotDepartment.", filtered rows: ".$rowsFiltered.", failed rows: ".$rowsFailed);
    }

}
