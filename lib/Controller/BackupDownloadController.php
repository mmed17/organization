<?php

declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

use OCA\Organization\Db\UserMapper;
use OCA\Organization\Service\OrganizationBackupService;

class BackupDownloadController extends Controller
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
    public function download(int $organizationId, int $jobId)
    {
        if (!$this->canManageOrganization($organizationId)) {
            return new NotFoundResponse();
        }

        try {
            $job = $this->backupService->getJob($organizationId, $jobId);
        } catch (\Throwable) {
            return new NotFoundResponse();
        }

        if (($job['status'] ?? '') !== 'completed') {
            return new NotFoundResponse();
        }

        $artifactName = isset($job['artifactName']) ? (string) $job['artifactName'] : '';
        if ($artifactName === '') {
            return new NotFoundResponse();
        }

        $expiresAt = (string) ($job['expiresAt'] ?? '');
        if ($expiresAt !== '' && $this->isExpired($expiresAt)) {
            return new NotFoundResponse();
        }

        if (!$this->backupService->artifactExists($organizationId, $artifactName)) {
            return new NotFoundResponse();
        }

        $stream = $this->backupService->openArtifactStream($organizationId, $artifactName);
        $response = new StreamResponse($stream);
        $response->addHeader('Content-Type', 'application/zip');
        $response->addHeader('Content-Disposition', sprintf('attachment; filename="%s"', $artifactName));

        return $response;
    }

    private function canManageOrganization(int $organizationId): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        $userId = $user->getUID();
        if ($this->groupManager->isAdmin($userId)) {
            return true;
        }

        $membership = $this->userMapper->getOrganizationMembership($userId);
        if ($membership === null) {
            return false;
        }

        if (($membership['role'] ?? '') !== 'admin') {
            return false;
        }

        return (int) $membership['organization_id'] === $organizationId;
    }

    private function isExpired(string $expiresAt): bool
    {
        try {
            $expires = new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return false;
        }

        return $expires < new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}

