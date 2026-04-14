<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Organization\Db\UserMapper;

use OCP\IGroupManager;

class TalkOrganizationPolicyService
{
    public function __construct(
        private UserMapper $userMapper,
        private IGroupManager $groupManager,
    ) {
    }

    public function isGlobalAdmin(?string $userId): bool
    {
        return $userId !== null && $userId !== '' && $this->groupManager->isAdmin($userId);
    }

    public function canUserUseTalk(?string $userId): bool
    {
        if ($userId === null || $userId === '') {
            return false;
        }

        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        return $this->userMapper->getOrganizationMembership($userId) !== null;
    }

    public function getOrganizationIdForUser(?string $userId): ?int
    {
        if ($userId === null || $userId === '' || $this->isGlobalAdmin($userId)) {
            return null;
        }

        return $this->userMapper->getOrganizationMembership($userId)['organization_id'] ?? null;
    }

    /**
     * @param string[] $userIds
     * @return array<string,?int>
     */
    public function getOrganizationIdsForUsers(array $userIds): array
    {
        $organizationIds = [];
        $membershipCandidateIds = [];

        foreach (array_values(array_unique($userIds)) as $userId) {
            if ($userId === '') {
                continue;
            }

            if ($this->isGlobalAdmin($userId)) {
                $organizationIds[$userId] = null;
                continue;
            }

            $membershipCandidateIds[] = $userId;
            $organizationIds[$userId] = null;
        }

        $memberships = $this->userMapper->getOrganizationMemberships($membershipCandidateIds);
        foreach ($memberships as $userId => $membership) {
            $organizationIds[$userId] = $membership['organization_id'];
        }

        return $organizationIds;
    }

    public function canUsersCommunicate(?string $firstUserId, ?string $secondUserId): bool
    {
        if ($firstUserId === null || $firstUserId === '' || $secondUserId === null || $secondUserId === '') {
            return false;
        }

        if ($this->isGlobalAdmin($firstUserId) || $this->isGlobalAdmin($secondUserId)) {
            return true;
        }

        $organizationIds = $this->getOrganizationIdsForUsers([$firstUserId, $secondUserId]);
        $firstOrganizationId = $organizationIds[$firstUserId] ?? null;
        $secondOrganizationId = $organizationIds[$secondUserId] ?? null;

        return $firstOrganizationId !== null
            && $secondOrganizationId !== null
            && $firstOrganizationId === $secondOrganizationId;
    }

    /**
     * @param string[] $candidateUserIds
     * @return string[]
     */
    public function filterReachableUserIds(?string $requestUserId, array $candidateUserIds): array
    {
        $candidateUserIds = array_values(array_unique(array_filter(array_map('trim', $candidateUserIds), static fn (string $userId): bool => $userId !== '')));
        if ($candidateUserIds === []) {
            return [];
        }

        if ($this->isGlobalAdmin($requestUserId)) {
            return $candidateUserIds;
        }

        if (!$this->canUserUseTalk($requestUserId)) {
            return [];
        }

        $reachableUserIds = [];
        foreach ($candidateUserIds as $candidateUserId) {
            if ($this->canUsersCommunicate($requestUserId, $candidateUserId)) {
                $reachableUserIds[] = $candidateUserId;
            }
        }

        return $reachableUserIds;
    }

    /**
     * @param string[] $roomUserIds
     */
    public function canUserAccessRoom(?string $requestUserId, array $roomUserIds): bool
    {
        if ($this->isGlobalAdmin($requestUserId)) {
            return true;
        }

        if (!$this->canUserUseTalk($requestUserId)) {
            return false;
        }

        $roomUserIds = array_values(array_unique(array_filter(array_map('trim', $roomUserIds), static fn (string $userId): bool => $userId !== '')));
        foreach ($roomUserIds as $roomUserId) {
            if (!$this->canUsersCommunicate($requestUserId, $roomUserId)) {
                return false;
            }
        }

        return true;
    }
}
