<?php
declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Organization\Db\PlanMapper;

class PlanService
{
    private PlanMapper $planMapper;

    public function __construct(
        PlanMapper $planMapper
    ) {
        $this->planMapper = $planMapper;
    }

    /**
     * Handles plan update logic - decides whether to update existing plan or create a new custom one.
     */
    public function handlePlanUpdate(
        int $newPlanId,
        int $originalPlanId,
        int $maxMembers,
        int $maxProjects,
        int $sharedStorage,
        int $privateStorage,
        ?float $price,
        ?string $currency,
        int $organizationId
    ): int {
        $originalPlan = $this->planMapper->find($originalPlanId);
        $newPlan = $this->planMapper->find($newPlanId);

        // SCENARIO 1: Switching to a public plan
        if ($newPlan !== null && $newPlan->getIsPublic()) {
            return $newPlan->getId();
        }

        // SCENARIO 2: Editing an existing custom plan
        if (!$originalPlan->getIsPublic()) {
            $originalPlan->setMaxMembers($maxMembers);
            $originalPlan->setMaxProjects($maxProjects);
            $originalPlan->setSharedStoragePerProject($sharedStorage);
            $originalPlan->setPrivateStoragePerUser($privateStorage);
            $originalPlan->setPrice($price);
            $originalPlan->setCurrency($currency);
            $this->planMapper->update($originalPlan);
            return $originalPlan->getId();
        }

        // SCENARIO 3: Creating a new custom plan from a public plan
        $newCustomPlan = $this->planMapper->create(
            'Custom Plan for Org ' . $organizationId,
            $maxMembers,
            $maxProjects,
            $sharedStorage,
            $privateStorage,
            $price,
            $currency,
            false // Private plan
        );

        return $newCustomPlan->getId();
    }
}
