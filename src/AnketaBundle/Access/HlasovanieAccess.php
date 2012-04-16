<?php
/**
 * @copyright Copyright (c) 2012 The FMFI Anketa authors (see AUTHORS).
 * Use of this source code is governed by a license that can be
 * found in the LICENSE file in the project root directory.
 *
 * @package    Anketa
 * @subpackage Anketa__Access
 */

namespace AnketaBundle\Access;

use Doctrine\ORM\EntityManager;
use Symfony\Component\Security\Core\SecurityContextInterface;

class HlasovanieAccess
{
    /** @var SecurityContextInterface */
    private $security;

    /** @var EntityManager */
    private $em;

    /** @var string */
    private $allowedOrgUnit;

    /** @var mixed */
    private $user;

    /** @var \AnketaBundle\Entity\UserSeason */
    private $userSeason;

    public function __construct(SecurityContextInterface $security, EntityManager $em, $allowedOrgUnit) {
        $this->security = $security;
        $this->em = $em;
        $this->allowedOrgUnit = $allowedOrgUnit;
        $this->user = null;
        $this->userSeason = null;
    }

    /**
     * Returns the logged in user, or null if nobody is logged in.
     *
     * @return mixed
     */
    public function getUser() {
        if ($this->user === null && $this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            $token = $this->security->getToken();
            if ($token) $this->user = $token->getUser();
        }
        return $this->user;
    }

    /**
     * Returns the logged in user's UserSeason for the active season, or null
     * if nobody is logged in or the user doesn't have a UserSeason for the
     * active season.
     *
     * @return mixed
     */
    public function getUserSeason() {
        if ($this->userSeason === null && $this->getUser() !== null) {
            $activeSeason = $this->em->getRepository('AnketaBundle:Season')->getActiveSeason();
            $this->userSeason = $this->em->getRepository('AnketaBundle:UserSeason')->findOneBy(array('user' => $this->getUser()->getId(), 'season' => $activeSeason->getId()));
        }
        return $this->userSeason;
    }

    /**
     * Returns whether the voting period is currently running (regardless of
     * whether the current user can vote).
     *
     * @return boolean
     */
    public function isVotingOpen() {
        $activeSeason = $this->em->getRepository('AnketaBundle:Season')->getActiveSeason();
        return $activeSeason->getVotingOpen();
    }

    /**
     * Returns whether the current user is a student.
     *
     * @return boolean
     */
    public function userIsStudent() {
        return $this->getUserSeason() && $this->getUserSeason()->getIsStudent();
    }

    /**
     * Returns whether the current user belongs to the allowed org. unit.
     *
     * @return boolean
     */
    public function userHasAllowedOrgUnit() {
        return !$this->allowedOrgUnit || ($this->getUser() && in_array($this->allowedOrgUnit, $this->getUser()->getOrgUnits()));
    }

    /**
     * Returns whether the current user can participate in voting.
     *
     * @return boolean
     */
    public function userCanVote() {
        if ($this->security->isGranted('ROLE_ADMIN')) return true;
        return $this->isVotingOpen() && $this->getUserSeason() && $this->getUserSeason()->canVote() && $this->userHasAllowedOrgUnit();
    }

}
