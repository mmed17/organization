<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCP\IURLGenerator;
use OCP\Notification\IManager;

use OCA\Organization\Db\UserMapper;
use OCA\Organization\Notification\NotificationConstants;

use DateTime;
use DateTimeZone;
use Psr\Log\LoggerInterface;

final class NotificationService
{
    public function __construct(
        private IManager $notificationManager,
        private IURLGenerator $urlGenerator,
        private UserMapper $userMapper,
        private LoggerInterface $logger,
    ) {
    }

    public function notifySubscriptionStatusChanged(
        int $organizationId,
        string $organizationName,
        string $oldStatus,
        string $newStatus,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_SUBSCRIPTION_STATUS_CHANGED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'oldStatus' => $oldStatus,
                'newStatus' => $newStatus,
            ],
        );
    }

    public function notifySubscriptionExtended(
        int $organizationId,
        string $organizationName,
        string $newEndedAt,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_SUBSCRIPTION_EXTENDED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'newEndedAt' => $newEndedAt,
            ],
        );
    }

    public function notifySubscriptionExpired(
        int $organizationId,
        string $organizationName,
        ?string $endedAt,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            null,
            NotificationConstants::SUBJECT_SUBSCRIPTION_EXPIRED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'endedAt' => $endedAt,
            ],
        );
    }

    public function notifyOrganizationMemberAdded(
        int $organizationId,
        string $organizationName,
        string $memberUserId,
        ?string $memberDisplayName,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_ORGANIZATION_MEMBER_ADDED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'memberUserId' => $memberUserId,
                'memberDisplayName' => $memberDisplayName ?? $memberUserId,
            ],
        );
    }

    public function notifyOrganizationMemberRemoved(
        int $organizationId,
        string $organizationName,
        string $memberUserId,
        ?string $memberDisplayName,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_ORGANIZATION_MEMBER_REMOVED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'memberUserId' => $memberUserId,
                'memberDisplayName' => $memberDisplayName ?? $memberUserId,
            ],
        );
    }

    public function notifyOrganizationHandoverStarted(
        int $organizationId,
        string $organizationName,
        string $sourceUserId,
        ?string $sourceDisplayName,
        string $targetUserId,
        ?string $targetDisplayName,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_ORGANIZATION_HANDOVER_STARTED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'sourceUserId' => $sourceUserId,
                'sourceDisplayName' => $sourceDisplayName ?? $sourceUserId,
                'targetUserId' => $targetUserId,
                'targetDisplayName' => $targetDisplayName ?? $targetUserId,
            ],
        );
    }

    public function notifyOrganizationHandoverCompleted(
        int $organizationId,
        string $organizationName,
        string $sourceUserId,
        ?string $sourceDisplayName,
        string $targetUserId,
        ?string $targetDisplayName,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_ORGANIZATION_HANDOVER_COMPLETED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'sourceUserId' => $sourceUserId,
                'sourceDisplayName' => $sourceDisplayName ?? $sourceUserId,
                'targetUserId' => $targetUserId,
                'targetDisplayName' => $targetDisplayName ?? $targetUserId,
            ],
        );
    }

    public function notifyOrganizationHandoverFailed(
        int $organizationId,
        string $organizationName,
        string $sourceUserId,
        ?string $sourceDisplayName,
        string $targetUserId,
        ?string $targetDisplayName,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_ORGANIZATION_HANDOVER_FAILED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'sourceUserId' => $sourceUserId,
                'sourceDisplayName' => $sourceDisplayName ?? $sourceUserId,
                'targetUserId' => $targetUserId,
                'targetDisplayName' => $targetDisplayName ?? $targetUserId,
            ],
        );
    }

    /**
     * @param array<string,mixed> $subjectParameters
     */
    private function notifyOrganizationMembers(
        int $organizationId,
        ?string $actorUid,
        string $subject,
        array $subjectParameters,
    ): void {
        $members = $this->userMapper->getOrganizationMembers($organizationId);
        if ($members === []) {
            return;
        }

        $dateTime = new DateTime('now', new DateTimeZone('UTC'));
        $link = $this->urlGenerator->linkToRouteAbsolute('organization.Page.index');

        foreach ($members as $member) {
            $userId = (string) $member['user_uid'];
            if ($actorUid !== null && $userId === $actorUid) {
                continue;
            }

            try {
                $notification = $this->notificationManager->createNotification();
                $notification->setApp(NotificationConstants::APP_ID);
                $notification->setUser($userId);
                $notification->setDateTime($dateTime);
                $notification->setObject(NotificationConstants::OBJECT_TYPE_ORGANIZATION, (string) $organizationId);
                $notification->setSubject($subject, $subjectParameters);
                $notification->setLink($link);

                $this->notificationManager->notify($notification);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send notification', [
                    'app' => NotificationConstants::APP_ID,
                    'subject' => $subject,
                    'orgId' => $organizationId,
                    'userId' => $userId,
                    'exception' => $e,
                ]);
            }
        }
    }
}
