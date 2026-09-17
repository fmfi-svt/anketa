<?php

namespace AnketaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @ORM\Entity(repositoryClass="AnketaBundle\Entity\UserRepository")
 */
class User implements UserInterface, EquatableInterface {

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    protected $id;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    protected $displayName;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    protected $givenName;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    protected $familyName;

    /**
     * @ORM\ManyToMany(targetEntity="Role")
     * @ORM\JoinTable(name="UsersRoles",
     *      joinColumns={@ORM\JoinColumn(name="user_id", referencedColumnName="id")},
     *      inverseJoinColumns={@ORM\JoinColumn(name="role_id", referencedColumnName="id")}
     *      )
     */
    protected $roles;

    /**
     * @ORM\Column(type="string", nullable=true, unique=true)
     */
    protected $login;

    /**
     * Ak sa niekedy bude menit department na NOT NULL, tak treba updatnut
     * ImportRozvrhXMLCommand, vid koment tam.
     * 
     * @ORM\ManyToOne(targetEntity="Department")
     * @var Department
     */
    protected $department;

    /**
     * Ci si tento ucitel vyziadal, aby sa neukazovali ziadne jeho vysledky.
     *
     * @ORM\Column(type="boolean", nullable=false)
     */
    protected $hideAllResults = true;

    /**
     * @param String $username
     */
    public function __construct($login) {
        $this->roles = new ArrayCollection();
        $this->setLogin($login);
    }

    public function getId() {
        return $this->id;
    }

    public function setDisplayName($value) {
        $this->displayName = $value;
    }

    public function getDisplayName() {
        return $this->displayName;
    }

    /**
     * @param ArrayCollection $value
     */
    public function setRoles($value) {
        $this->roles = $value;
    }

    /**
     * @param Role $value
     */
    public function addRole($value) {
        if ($this->hasRole($value)) return;
        $this->roles[] = $value;
    }

    /**
     * @return string[] roles
     */
    public function getRoles() {
        $roles = array('ROLE_USER');
        foreach ($this->roles as $role) {
            $roles[] = $role->getRole();
        }
        return $roles;
    }

    public function hasRole($role) {
        if ($role instanceof Role) {
            $role = $role->getRole();
        }
        return array_search($role, $this->getRoles()) !== false;
    }

    public function isEqualTo(UserInterface $user) {
        if (!$user instanceof User) {
            return false;
        }

        return $this->login === $user->getLogin();
    }

    public function eraseCredentials() {
    }

    public function getPassword() {
        return null;
    }

    public function getSalt() {
        return null;
    }

    public function __toString() {
        return $this->getLogin();
    }

    public function getName() {
        $name = trim($this->getGivenName() . ' ' . $this->getFamilyName());
        if ($name == '') return null;
        return $name;
    }

    public function getGivenName() {
        return $this->givenName;
    }

    public function setGivenName($givenName) {
        $this->givenName = $givenName;
    }

    public function getFamilyName() {
        return $this->familyName;
    }

    public function setFamilyName($familyName) {
        $this->familyName = $familyName;
    }


    public function getFormattedName() {
        $formattedName = $this->getDisplayName() ?: $this->getName() ?: $this->getLogin() ?: null;
        if ($formattedName === null) {
                throw new \Exception('Neda sa vygenerovat formatovane meno pre pouzivatela s id ' . $this->getId());
        }
        return $formattedName;
    }

    public function getLogin() {
        return $this->login;
    }

    public function setLogin($login) {
        $this->login = $login;
    }

    /**
     * @return Department
     */
    public function getDepartment() {
        return $this->department;
    }

    /**
     * @param Department $department
     */
    public function setDepartment($department) {
        $this->department = $department;
    }

    public function getHideAllResults() {
        return $this->hideAllResults;
    }

    public function setHideAllResults($hideAllResults) {
        $this->hideAllResults = $hideAllResults;
    }

    /**
     * Kvoli Symfony UserInterface
     */
    public function getUsername() {
        return $this->getLogin();
    }
}
