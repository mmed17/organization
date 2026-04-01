<?php

declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

use OCA\Organization\Db\UserMapper;
use OCA\Organization\Service\OrganizationBackupService;

class BackupController extends OCSController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private OrganizationBackupService $backupService,
        private UserMapper $userMapper,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[PasswordConfirmationRequired]
    public function createBackupJob(int $organizationId, ?string $backupType = null): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $options = [
            'includeProjectCreator' => true,
            'includeDeck' => true,
            'includeSharedFiles' => true,
            'excludePrivateData' => true,
        ];

        $resolvedBackupType = strtolower(trim((string) ($backupType ?? 'full')));
        if (!in_array($resolvedBackupType, ['full', 'incremental'], true)) {
            throw new OCSException('Invalid backup type. Supported values: full, incremental', 400);
        }

        $job = $this->backupService->createJob(
            $organizationId,
            $user->getUID(),
            $options,
            $resolvedBackupType,
            'manual',
        );

        return new DataResponse([
            'job' => $job,
        ]);
    }

    #[NoAdminRequired]
    public function listMyOrganizationBackupJobs(?string $status = null, int $limit = 20, int $offset = 0): DataResponse
    {
        $organizationId = $this->resolveCurrentUserMembershipOrganizationId(true);

        return new DataResponse($this->backupService->listJobs($organizationId, $status, $limit, $offset));
    }

    #[NoAdminRequired]
    public function listBackupJobs(int $organizationId, ?string $status = null, int $limit = 20, int $offset = 0): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        return new DataResponse($this->backupService->listJobs($organizationId, $status, $limit, $offset));
    }

    #[NoAdminRequired]
    public function getBackupJob(int $organizationId, int $jobId): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        try {
            $job = $this->backupService->getJob($organizationId, $jobId);
        } catch (\InvalidArgumentException) {
            throw new OCSNotFoundException('Job not found');
        }

        return new DataResponse([
            'job' => $job,
        ]);
    }

    #[NoAdminRequired]
    public function listBackupEvents(int $organizationId, int $jobId, int $limit = 200, int $offset = 0): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        try {
            $events = $this->backupService->listEvents($organizationId, $jobId, $limit, $offset);
        } catch (\InvalidArgumentException) {
            throw new OCSNotFoundException('Job not found');
        }

        return new DataResponse($events);
    }

    #[NoAdminRequired]
    #[PasswordConfirmationRequired]
    public function deleteBackupJob(int $organizationId, int $jobId): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        try {
            $this->backupService->deleteJob($organizationId, $jobId);
        } catch (\InvalidArgumentException) {
            throw new OCSNotFoundException('Job not found');
        }

        return new DataResponse(['status' => 'ok']);
    }

    /**
     * Returns null for global admins, otherwise the organization id of current org admin user.
     */
    private function resolveOrganizationForCurrentUser(bool $mustBeOrgAdmin = true): ?int
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $userId = $user->getUID();
        if ($this->groupManager->isAdmin($userId)) {
            return null;
        }

        $membership = $this->userMapper->getOrganizationMembership($userId);
        if ($membership === null) {
            throw new OCSForbiddenException('You are not assigned to an organization');
        }

        if ($mustBeOrgAdmin && $membership['role'] !== 'admin') {
            throw new OCSForbiddenException('Only organization admins can access this resource');
        }

        return (int) $membership['organization_id'];
    }

    private function assertCanManageOrganization(int $organizationId, bool $mustBeOrgAdmin): void
    {
        $allowedOrganizationId = $this->resolveOrganizationForCurrentUser($mustBeOrgAdmin);
        if ($allowedOrganizationId !== null && $allowedOrganizationId !== $organizationId) {
            throw new OCSNotFoundException('Organization does not exist');
        }
    }

    private function resolveCurrentUserMembershipOrganizationId(bool $mustBeOrgAdmin = true): int
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $membership = $this->userMapper->getOrganizationMembership($user->getUID());
        if ($membership === null) {
            throw new OCSForbiddenException('You are not assigned to an organization');
        }

        if ($mustBeOrgAdmin && ($membership['role'] ?? '') !== 'admin') {
            throw new OCSForbiddenException('Only organization admins can access this resource');
        }

        return (int) $membership['organization_id'];
    }
}
