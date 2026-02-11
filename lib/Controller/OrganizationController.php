<?php
declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\SubscriptionMapper;
use OCA\Organization\Db\PlanMapper;
use OCA\Organization\Service\OrganizationService;
use OCA\Organization\Service\SubscriptionService;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCA\Settings\Settings\Admin\Users;
use Exception;
use OCP\AppFramework\OCS\OCSException;

class OrganizationController extends OCSController
{

    public function __construct(
        string $appName,
        IRequest $request,
        private OrganizationMapper $organizationMapper,
        private SubscriptionMapper $subscriptionMapper,
        private PlanMapper $planMapper,
        private OrganizationService $organizationService,
        private SubscriptionService $subscriptionService,
        private IUserSession $userSession,
        private IDBConnection $db,
        private LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get all organizations with subscription details.
     *
     * @param string $search Search query
     * @param int|null $limit Limit results
     * @param int $offset Offset for pagination
     * @return DataResponse
     */
    #[AuthorizedAdminSetting(settings: Users::class)]
    public function getOrganizations(string $search = '', ?int $limit = null, int $offset = 0): DataResponse
    {
        // Query organizations directly (organization-centric, no N+1 queries)
        $orgRows = $this->organizationMapper->findAll($search, $limit, $offset);

        $organizations = [];
        foreach ($orgRows as $row) {
            $userCount = $this->organizationMapper->getUserCountById((int) $row['id']);

            $organizations[] = [
                'id' => (int) $row['id'],
                'displayname' => $row['name'],
                'usercount' => $userCount,
                'disabled' => 0,
                'canAdd' => true,
                'canRemove' => true,
                'isOrganization' => true,
                'subscription' => [
                    'id' => $row['subscription_id'],
                    'status' => $row['subscription_status'],
                    'startedAt' => $row['started_at'],
                    'endedAt' => $row['ended_at'],
                    'planId' => $row['plan_id'],
                    'planName' => $row['plan_name'],
                    'maxMembers' => $row['max_members'],
                    'maxProjects' => $row['max_projects'],
                ],
            ];
        }

        return new DataResponse(['organizations' => $organizations]);
    }

    /**
     * Get organization details including subscription and plan.
     *
     * @param int $organizationId ID of the organization
     * @return DataResponse
     * @throws OCSNotFoundException
     */
    #[NoAdminRequired]
    public function getOrganization(int $organizationId): DataResponse
    {
        $organization = $this->organizationMapper->find($organizationId);
        if ($organization === null) {
            throw new OCSNotFoundException('Organization does not exist');
        }

        $subscription = $this->subscriptionMapper->findByOrganizationId($organization->getId());
        if ($subscription === null) {
            throw new OCSNotFoundException('No active subscription found for this organization');
        }

        $plan = $this->planMapper->find($subscription->getPlanId());
        if ($plan === null) {
            throw new OCSNotFoundException('The plan associated with this subscription could not be found');
        }

        return new DataResponse([
            'organization' => $organization,
            'subscription' => $subscription,
            'plan' => $plan,
        ]);
    }

    /**
     * Create a new organization with subscription.
     *
     * @param string $validity Duration string (e.g., "1 year")
     * @param int|null $memberLimit Max members
     * @param int|null $projectsLimit Max projects
     * @param int|null $sharedStoragePerProject Shared storage in bytes
     * @param int|null $privateStorage Private storage in bytes
     * @param int|null $planId Plan ID (null for custom plan)
     * @param float|null $price Price
     * @param string|null $currency Currency code
     * @param string|null $displayname Display name
     * @param string|null $contactFirstName First name of contact person
     * @param string|null $contactLastName Last name of contact person
     * @param string|null $contactEmail Email of contact person
     * @param string|null $contactPhone Phone of contact person
     * @return DataResponse
     * @throws OCSException
     */
    #[AuthorizedAdminSetting(settings: Users::class)]
    #[PasswordConfirmationRequired]
    public function createOrganization(
        string $validity,
        ?int $memberLimit,
        ?int $projectsLimit,
        ?int $sharedStoragePerProject,
        ?int $privateStorage,
        ?int $planId,
        ?float $price,
        ?string $currency = 'EUR',
        ?string $displayname = '',
        ?string $contactFirstName = null,
        ?string $contactLastName = null,
        ?string $contactEmail = null,
        ?string $contactPhone = null
    ): DataResponse {

        try {
            $this->db->beginTransaction();

            $organization = $this->organizationService->createOrganization(
                $displayname,
                $contactFirstName,
                $contactLastName,
                $contactEmail,
                $contactPhone
            );

            if ($organization === null) {
                throw new OCSException('Failed to create organization', 104);
            }

            $subscription = $this->subscriptionService->createSubscription(
                $organization->getId(),
                $validity,
                $planId,
                $memberLimit,
                $projectsLimit,
                $sharedStoragePerProject,
                $privateStorage,
                $price,
                $currency
            );

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw new OCSException('Failed to create organization: ' . $e->getMessage(), 104);
        }

        return new DataResponse($subscription);
    }

    /**
     * Update an organization's subscription.
     *
     * @param int $organizationId The organization ID
     * @param string $displayName New display name
     * @param int $planId Plan ID
     * @param int $maxMembers Max members
     * @param int $maxProjects Max projects
     * @param int $sharedStoragePerProject Shared storage in bytes
     * @param int $privateStoragePerUser Private storage in bytes
     * @param string $status Subscription status
     * @param string|null $extendDuration Duration to extend
     * @param float|null $price Price
     * @param string|null $currency Currency code
     * @return DataResponse
     * @throws OCSException
     */
    #[AuthorizedAdminSetting(settings: Users::class)]
    #[PasswordConfirmationRequired]
    public function updateSubscription(
        int $organizationId,
        string $displayName,
        int $planId,
        int $maxMembers,
        int $maxProjects,
        int $sharedStoragePerProject,
        int $privateStoragePerUser,
        string $status,
        ?string $extendDuration = null,
        ?float $price = null,
        ?string $currency = null
    ): DataResponse {
        $this->db->beginTransaction();
        try {
            $updatedSubscription = $this->subscriptionService->updateSubscription(
                $organizationId,
                $displayName,
                $planId,
                $maxMembers,
                $maxProjects,
                $sharedStoragePerProject,
                $privateStoragePerUser,
                $status,
                $extendDuration,
                $price,
                $currency,
                $this->userSession->getUser()->getUID()
            );

            $this->db->commit();
            return new DataResponse(['subscription' => $updatedSubscription]);

        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to update organization: ' . $e->getMessage(), ['exception' => $e]);
            if ($e instanceof OCSNotFoundException) {
                throw $e;
            }
            throw new OCSException('Failed to update organization: ' . $e->getMessage());
        }
    }
}
