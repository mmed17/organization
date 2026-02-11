<?php
declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IInitialStateService $initialStateService
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        // Initial state can be added here
        $this->initialStateService->provideInitialState($this->appName, 'settings', [
            'appId' => $this->appName,
        ]);

        Util::addScript($this->appName, 'organization-main');
        Util::addStyle($this->appName, 'organization-main');

        return new TemplateResponse($this->appName, 'index');
    }
}
