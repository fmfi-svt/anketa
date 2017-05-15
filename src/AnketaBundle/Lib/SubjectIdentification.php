<?php
/**
 * @copyright Copyright (c) 2012 The FMFI Anketa authors (see AUTHORS).
 * Use of this source code is governed by a license that can be
 * found in the LICENSE file in the project root directory.
 *
 * @package    Anketa
 * @author     Martin Sucha <anty.sk+svt@gmail.com>
 */

namespace AnketaBundle\Lib;

class SubjectIdentification implements SubjectIdentificationInterface
{

    /**
     * {@inheritdoc}
     */
    public function identify($longCode, $subjectName) {
        $faculty = $this->getFaculty($longCode);
        if ($faculty == 'FMFI') {
            $shortCode = $this->getShortCode($longCode);
            return array(
                'code' => $shortCode,
                'name' => $subjectName,
                'slug' => $this->slugify($shortCode),
            );
        }
        else {
            return array(
                'code' => $longCode,
                'name' => $subjectName,
                'slug' => $this->slugify($longCode),
            );
        }
    }

    private function transliterate($string)
    {
        $oldLocale = setlocale(LC_CTYPE, "0");
        $newLocale = 'en_US.UTF-8';

        $status = setlocale(LC_CTYPE, $newLocale);
        $result = iconv('UTF-8', 'ASCII//TRANSLIT', $string);
        $test = iconv('UTF-8', 'ASCII//TRANSLIT', 'Žltučký kôň Üʿö');
        setlocale(LC_CTYPE, $oldLocale);

        if ($status === FALSE) {
            throw new \Exception("Nepodarilo sa setlocale($newLocale).");
        }
        if ($test !== 'Zltucky kon U?o') {
            throw new \Exception('Transliteracia nefunguje.');
        }
        return $result;
    }

    private function slugify($string)
    {
        $slug = $this->transliterate($string);
        $slug = preg_replace('@[^a-zA-Z0-9_/]@', '-', $slug);
        $slug = preg_replace('@-+@', '-', $slug);
        $slug = preg_replace('@/@', '--', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }

    private function getFaculty($longCode)
    {
        $parts = explode(".", $longCode);
        return $parts[0];
    }

    private function getShortCode($longCode)
    {
        $matches = array();
        if (preg_match('@^[^/]*/(.*)$@', $longCode, $matches) !== 1) {
            // Nevieme zistit kratky kod
            return $longCode;
        }

        return $matches[1];
    }

}
