<?php

namespace AnketaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="AnketaBundle\Entity\SubjectRepository")
 */
class Subject {

    const NO_CATEGORY = 'XXX-nekategorizovane';

    /**
     * @ORM\Id @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    protected $id;

    /**
     * Does not (need to) uniquely identify the subject!
     * @ORM\Column(type="string", nullable=false, unique=true)
     */
    protected $code;

    /**
     * Uniquely identifies the subject, is suitable to be used as
     * part of an URL.
     *
     * (i.e. alphanumeric chars with not repeated dashes)
     *
     * @ORM\Column(type="string", nullable=false, unique=true)
     */
    protected $slug;

    /**
     * @ORM\Column(type="string", nullable=false)
     */
    protected $name;

    /**
     * @param String $name
     */
    public function __construct($name) {
        $this->name = $name;
    }

    public function getId() {
        return $this->id;
    }

    public function setCode($value) {
        $this->code = $value;
    }

    public function getCode() {
        return $this->code;
    }

    public function setSlug($slug) {
        $this->slug = $slug;
    }

    public function getSlug() {
        return $this->slug;
    }

    public function setName($value) {
        $this->name = $value;
    }

    public function getName() {
        return $this->name;
    }

    /**
     * Vrat nazov kategorie pre predmet
     * @return string nazov kategorie alebo Subject::NO_CATEGORY ak je nekategorizovany
     */
    public function getCategory()
    {
        $matches = array();
        $stred = preg_match('@^[^/]*/([^/]*)/.*$@', $this->getCode(), $matches);
        if ($stred == 0) {
            // Ide o kratky kod typu 1-XXX-NNN/nn
            $zvysok = $this->getCode();
        }
        else {
            // Ide o dlhy kod typu FMFI-PriF.XXXX/1-XXX-NNN/nn
            $zvysok = $matches[1];
        }
        $matches = array();
        $match = preg_match("@^[^-]*-([^-]*)-@", $zvysok, $matches);
        if ($match == 0) {
            $matches = array();
            if (preg_match("@-(Bc|Mgr)@", $zvysok, $matches) == 0) {
                return self::NO_CATEGORY;
            }
            else {
                return $matches[1];
            }
        } else {
            return $matches[1];
        }
    }

}
