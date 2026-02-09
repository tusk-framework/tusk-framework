<?php

namespace Tusk\Security\Authorization;

use Tusk\Security\Contract\GuardInterface;
use Tusk\Security\Contract\UserInterface;

class Gate
{
    /** @var VoterInterface[] */
    private array $voters = [];

    public function __construct(
        private GuardInterface $guard
    ) {
    }

    public function addVoter(VoterInterface $voter): void
    {
        $this->voters[] = $voter;
    }

    public function allows(string $attribute, mixed $subject = null): bool
    {
        $user = $this->guard->user();

        if (!$user) {
            return false;
        }

        return $this->vote($user, $attribute, $subject) === VoterInterface::ACCESS_GRANTED;
    }

    public function denies(string $attribute, mixed $subject = null): bool
    {
        return !$this->allows($attribute, $subject);
    }

    private function vote(UserInterface $user, string $attribute, mixed $subject): int
    {
        $grant = VoterInterface::ACCESS_ABSTAIN;

        foreach ($this->voters as $voter) {
            if (!$voter->supports($attribute, $subject)) {
                continue;
            }

            $vote = $voter->vote($user, $attribute, $subject);

            if ($vote === VoterInterface::ACCESS_DENIED) {
                return VoterInterface::ACCESS_DENIED;
            }

            if ($vote === VoterInterface::ACCESS_GRANTED) {
                $grant = VoterInterface::ACCESS_GRANTED;
            }
        }

        return $grant;
    }
}
