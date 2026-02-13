<?php
declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCA\Organization\Db\UserMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\IGroupManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IInitialStateService $initialStateService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private UserMapper $userMapper,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse|NotFoundResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new NotFoundResponse();
        }

        $userId = $user->getUID();
        $isGlobalAdmin = $this->groupManager->isAdmin($userId);
        $membership = $this->userMapper->getOrganizationMembership($userId);
        $isOrganizationAdmin = $membership !== null && $membership['role'] === 'admin';

        if (!$isGlobalAdmin && !$isOrganizationAdmin) {
            return new NotFoundResponse();
        }

        $this->initialStateService->provideInitialState($this->appName, 'settings', [
            'appId' => $this->appName,
            'permissions' => [
                'isGlobalAdmin' => $isGlobalAdmin,
                'isOrganizationAdmin' => $isOrganizationAdmin,
                'organizationId' => $membership['organization_id'] ?? null,
            ],
        ]);

        Util::addScript($this->appName, 'organization-main');
        Util::addStyle($this->appName, 'organization-main');

        return new TemplateResponse($this->appName, 'index');
    }
}
