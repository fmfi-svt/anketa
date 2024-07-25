<?php

/**
 * @copyright Copyright (c) 2012 The FMFI Anketa authors (see AUTHORS).
 * Use of this source code is governed by a license that can be
 * found in the LICENSE file in the project root directory.
 *
 * @package    Anketa
 * @author     Martin Sucha <anty.sk@gmail.com>
 */

namespace AnketaBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use AnketaBundle\Lib\RozvrhXMLImporter;
use SVT\RozvrhXML\Parser;
use Exception;

/**
 * Command for importing Teachers/Subjects from rozvrh.xml
 *
 * @package    Anketa
 * @author     Martin Sucha <anty.sk@gmail.com>
 */
class ImportRozvrhXMLCommand extends AbstractImportCommand {

    protected function configure() {
        parent::configure();

        $this
                ->setName('anketa:import:rozvrh-xml')
                ->setDescription('Importuj ucitelov-predmety a ucitelov-katedry z xml-ka')
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
        $katedraCodeMap = array(
            'KAFZM' => 'FMFI.KAFZM',
            'KAGDM' => 'FMFI.KAGDM',
            'KAI' => 'FMFI.KAI',
            'KAMS' => 'FMFI.KAMŠ',
            'KEF' => 'FMFI.KEF',
            'KI' => 'FMFI.KI',
            'KJFB' => 'FMFI.KJFB',
            'KJP' => 'FMFI.KJP',
            'KMANM' => 'FMFI.KMANM',
            'KTFDF' => 'FMFI.KTFDF',
            'KZVI' => 'FMFI.KZVI',
            'KTVS' => 'FMFI.KTV',
            'KTF' => 'FMFI.KTF',
            'KAG' => 'FMFI.KAG',
            'KAG' => 'FMFI.KAG',
            'KDMFI' => 'FMFI.KDMFI',
        );
        
        $subjectIdentification = $this->getContainer()->get('anketa.subject_identification');

        $season = $this->getSeason($input);
        $file = $this->openFile($input);

        $conn = $this->getContainer()->get('database_connection')->getWrappedConnection();

        $importer = new RozvrhXMLImporter($conn, $subjectIdentification, $katedraCodeMap);
        $importer->prepareDatabase();

        $parser = new Parser($importer);

        $conn->beginTransaction();

        $sql = 'CREATE TEMPORARY TABLE tmp_teachers_subjects ';
        $sql .= ' (teacher VARCHAR(50) binary not null collate utf8_bin, ';
        $sql .= ' subject VARCHAR(50) binary not null collate utf8_bin, ';
        $sql .= ' lecturer INTEGER not null, trainer INTEGER not null)';
        $conn->exec($sql);

        try {
            $importer->prepareTransaction();

            while (!feof($file)) {
                $data = fread($file, 4096);
                if ($data === false) {
                    throw new Exception('Failed reading file');
                }
                $parser->parse($data, false);
            }
            $parser->parse('', true);
            fclose($file);

            $sql = 'INSERT IGNORE INTO Subject(code, name, slug) ';
            $sql .= ' SELECT code, name, slug ';
            $sql .= ' FROM tmp_insert_subject s ';
            $sql .= ' WHERE NOT EXISTS (SELECT s2.id FROM Subject s2 WHERE s2.slug = s.slug)';
            $conn->exec($sql);

            $sql = 'INSERT INTO User (givenName, familyName, login, department_id) ';
            $sql .= " SELECT t.given_name, t.family_name, t.login, d.id ";
            $sql .= " FROM tmp_insert_teacher t LEFT JOIN Department d";
            $sql .= ' ON d.code = t.katedra';
            $sql .= ' WHERE t.login IS NOT NULL';
            // updatneme department, ak pri importe mame nejaky, ktory nie je NULL
            // Ak niekedy bude User.department_id NOT NULL, treba upravit tento riadok
            // vid http://mysqlsoapbox.blogspot.sk/2010/06/on-duplicate-key-update-gotcha.html
            $sql .= ' ON DUPLICATE KEY UPDATE department_id = IFNULL(VALUES(department_id), User.department_id)';
            $conn->exec($sql);

            // Teachers subjects sa importuje v troch krokoch:
            // 1) Najprv sa zisti, kto co uci, pricom sa to vklada iba raz ak uci viac hodin daneho predmetu
            // 2) Potom sa zisti, ci je to prednasajuci (existuje prednaska, ktoru uci) alebo cviciaci (obdobne)
            // 3) Potom sa zaznamy, ktore este v TeachersSubject nie su, prekopiruju z docasnej tabulky

            $sql = 'INSERT INTO tmp_teachers_subjects (teacher, subject, lecturer, trainer)';
            $sql .= ' SELECT DISTINCT tl.teacher_external_id, l.subject, 0, 0';
            $sql .= ' FROM tmp_insert_lesson l, tmp_insert_lesson_teacher tl';
            $sql .= ' WHERE tl.lesson_external_id = l.external_id';
            $conn->exec($sql);

            foreach (array('lecturer' => 'P', 'trainer' => 'C') as $column => $lt) {
                $sql = 'UPDATE tmp_teachers_subjects ts';
                $sql .= ' SET ts.' . $column . ' = 1';
                $sql .= ' WHERE EXISTS( ';
                $sql .=   'SELECT l.external_id FROM tmp_insert_lesson l, ';
                $sql .=   ' tmp_insert_lesson_teacher lt';
                $sql .=   ' WHERE ts.subject = l.subject AND l.external_id = lt.lesson_external_id';
                $sql .=   " AND lt.teacher_external_id = ts.teacher AND l.lesson_type = '" . $lt . "'";
                $sql .= ')';
                $conn->exec($sql);
            }

            // 3) V docasnej tabulke, bohuzial, mozu byt duplikaty, pokial sa kratke kody predmetov zhoduju.
            // Momentalne to riesime tak, ze "duplikaty" updatuju len, ci je dany ucitel cviciaci/prednasajuci,
            // pokial druhy predmet s rovnakou skratkou ma ine meno, toto meno sa odignoruje.

            $sql = 'INSERT INTO TeachersSubjects (teacher_id, subject_id, season_id, lecturer, trainer)';
            $sql .= ' SELECT t.id, s.id, :season, ts.lecturer, ts.trainer';
            $sql .= ' FROM User t, Subject s, tmp_teachers_subjects ts, ';
            $sql .= ' tmp_insert_teacher tt, tmp_insert_subject ss ';
            $sql .= ' WHERE t.login = tt.login AND tt.external_id = ts.teacher ';
            $sql .= ' AND s.slug = ss.slug AND ss.external_id = ts.subject ';
            $sql .= ' AND NOT EXISTS(';
            $sql .=     ' SELECT target.teacher_id, target.subject_id';
            $sql .=     ' FROM TeachersSubjects target';
            $sql .=     ' WHERE target.teacher_id = t.id AND target.subject_id = s.id AND target.season_id = :season';
            $sql .= ' ) ';
            $sql .= ' ON DUPLICATE KEY UPDATE lecturer=GREATEST(ts.lecturer, TeachersSubjects.lecturer),';
            $sql .= ' trainer=GREATEST(ts.trainer, TeachersSubjects.trainer)';
            $prep = $conn->prepare($sql);
            $prep->execute(array('season' => $season->getId()));

            // updatneme department, ak pri importe mame nejaky, ktory nie je NULL
            // Ak niekedy bude UserSeason.department_id NOT NULL, treba upravit tento statement
            // vid http://mysqlsoapbox.blogspot.sk/2010/06/on-duplicate-key-update-gotcha.html
            $insertUserSeason = $conn->prepare("
                    INSERT INTO UserSeason ( user_id, season_id, isTeacher, isStudent, loadedFromAis, department_id)
                    SELECT a.id, :seasonId, 1, 0, 0, d.id
                    FROM User a, tmp_insert_teacher tt
                    LEFT JOIN Department d ON d.code = tt.katedra
                    WHERE a.login = tt.login AND tt.login IS NOT NULL
                    ON DUPLICATE KEY UPDATE isTeacher=1,
                      department_id = IFNULL(VALUES(department_id), UserSeason.department_id)");
            $insertUserSeason->bindValue('seasonId', $season->getId());
            $insertUserSeason->execute();

        } catch (Exception $e) {
            $output->writeln('<error>' . $e->getTraceAsString() . '</error>');
            $conn->rollback();
            throw $e;
        }

        $conn->commit();
    }

}
