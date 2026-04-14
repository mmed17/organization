<?php

declare(strict_types=1);

namespace OCA\Organization\Listener;

use OCA\Organization\Service\TalkOrganizationPolicyService;

use OCP\Collaboration\AutoComplete\AutoCompleteFilterEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * @template-implements IEventListener<Event>
 */
class TalkAutoCompleteListener implements IEventListener
{
    public function __construct(
        private TalkOrganizationPolicyService $policyService,
        private IUserSession $userSession,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof AutoCompleteFilterEvent || $event->getItemType() !== 'call') {
            return;
        }

        $currentUserId = $this->userSession->getUser()?->getUID();
        if ($this->policyService->isGlobalAdmin($currentUserId)) {
            return;
        }

        $results = $event->getResults();
        if (!$this->policyService->canUserUseTalk($currentUserId)) {
            $event->setResults($this->stripAllNonGuestResults($results));
            return;
        }

        $allowedUserIds = $this->policyService->filterReachableUserIds(
            $currentUserId,
            $this->extractUserIds($results)
        );

        $event->setResults($this->filterResults($results, array_flip($allowedUserIds)));
    }

    /**
     * @param array<string,mixed> $results
     * @return string[]
     */
    private function extractUserIds(array $results): array
    {
        $userIds = [];
        foreach (['users', 'exact'] as $key) {
            $userRows = $key === 'exact' ? ($results['exact']['users'] ?? []) : ($results['users'] ?? []);
            foreach ($userRows as $row) {
                $userId = isset($row['value']['shareWith']) ? trim((string) $row['value']['shareWith']) : '';
                if ($userId !== '') {
                    $userIds[] = $userId;
                }
            }
        }

        return array_values(array_unique($userIds));
    }

    /**
     * @param array<string,mixed> $results
     * @param array<string,bool> $allowedUserIds
     * @return array<string,mixed>
     */
    private function filterResults(array $results, array $allowedUserIds): array
    {
        $results = $this->stripUnsupportedSources($results);

        $results['users'] = array_values(array_filter(
            $results['users'] ?? [],
            static fn (array $row): bool => isset($allowedUserIds[(string) ($row['value']['shareWith'] ?? '')])
        ));

        if (isset($results['exact']) && is_array($results['exact'])) {
            $results['exact']['users'] = array_values(array_filter(
                $results['exact']['users'] ?? [],
                static fn (array $row): bool => isset($allowedUserIds[(string) ($row['value']['shareWith'] ?? '')])
            ));
            $results['exact'] = array_filter($results['exact'], static fn (mixed $value): bool => is_array($value) ? $value !== [] : true);
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $results
     * @return array<string,mixed>
     */
    private function stripAllNonGuestResults(array $results): array
    {
        $results = $this->stripUnsupportedSources($results);
        $results['users'] = [];
        $results['emails'] = [];
        if (isset($results['exact']) && is_array($results['exact'])) {
            $results['exact']['users'] = [];
            $results['exact']['emails'] = [];
            $results['exact'] = array_filter($results['exact'], static fn (mixed $value): bool => is_array($value) ? $value !== [] : true);
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $results
     * @return array<string,mixed>
     */
    private function stripUnsupportedSources(array $results): array
    {
        foreach (['groups', 'circles', 'remotes', 'remote_groups', 'federated_users'] as $key) {
            unset($results[$key]);
            if (isset($results['exact'][$key])) {
                unset($results['exact'][$key]);
            }
        }

        return $results;
    }
}
