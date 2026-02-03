<?php

namespace OCA\Organization\Middleware;

use DateTime;
use DateTimeZone;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IGroupManager;
use OC\Core\Controller\LoginController;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\Http\TemplateResponse;
use OCA\Organization\Db\SubscriptionMapper;
use OCA\Organization\Db\OrganizationMapper;

/**
 * SubscriptionMiddleware
 *
 * Checks if a non-admin user has a valid and active subscription
 * before allowing access to protected routes.
 */
class SubscriptionMiddleware extends Middleware
{
    private IControllerMethodReflector $reflector;
    private IRequest $request;
    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private SubscriptionMapper $subscriptionMapper;
    private OrganizationMapper $organizationMapper;

    public function __construct(
        IControllerMethodReflector $reflector,
        IRequest $request,
        IUserSession $userSession,
        IGroupManager $groupManager,
        SubscriptionMapper $subscriptionMapper,
        OrganizationMapper $organizationMapper
    ) {
        $this->reflector = $reflector;
        $this->request = $request;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->subscriptionMapper = $subscriptionMapper;
        $this->organizationMapper = $organizationMapper;
    }

    /**
     * Executed before the controller action.
     */
    public function beforeController($controller, $methodName)
    {
        // 1. Bypass public routes
        if ($this->isPublicRoute($controller, $methodName)) {
            return;
        }

        // 2. Require authentication
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSForbiddenException('Authentication required to access this resource.');
        }

        // 3. Admins bypass all checks
        if ($this->groupManager->isAdmin($user->getUID())) {
            return;
        }

        // 4. Find user's organization
        $organization = $this->organizationMapper->findByUserId($user->getUID());

        if ($organization === null) {
            throw new OCSForbiddenException('You are not a member of a valid organization.');
        }

        // 5. Find the active subscription
        $subscription = $this->subscriptionMapper->findByOrganizationId($organization->getId());

        if ($subscription === null) {
            throw new OCSForbiddenException('Your organization does not have an active subscription. Please contact your administrator.');
        }

        // 6. Check if subscription end date has passed
        $endedAtString = $subscription->getEndedAt();
        if ($endedAtString == null) {
            throw new OCSForbiddenException('Your organization subscription has undetermined ending time. Please contact your administrator to renew.');
        }

        $now = new DateTime('now', new DateTimeZone('UTC'));
        $endedAt = new DateTime($endedAtString, new DateTimeZone('UTC'));

        if ($endedAt < $now) {
            throw new OCSForbiddenException('Your organization\'s subscription has expired. Please contact your administrator to renew.');
        }

        // 7. Check subscription status
        $status = $subscription->getStatus();
        switch ($status) {
            case 'active':
            case 'cancelled':
                // Allow access for active or cancelled (until end date)
                return;

            case 'paused':
                throw new OCSForbiddenException('Your organization\'s subscription is currently paused. Please contact your administrator to resume it.');

            default:
                throw new OCSForbiddenException('Your organization\'s subscription is not active. Please contact your administrator.');
        }
    }

    /**
     * Determines if a route is public.
     */
    private function isPublicRoute($controller, $methodName): bool
    {
        if ($this->reflector->hasAnnotation('NoLoginRequired')) {
            return true;
        }
        if (
            $controller instanceof LoginController &&
            in_array($methodName, ['showLoginForm', 'login', 'tryLogin'])
        ) {
            return true;
        }
        $pathInfo = $this->request->getPathInfo();
        if (in_array($pathInfo, ['/logout', '/index.php/logout'])) {
            return true;
        }
        return false;
    }

    /**
     * Catches exceptions to render user-friendly error pages.
     */
    public function afterException($controller, $methodName, \Exception $exception)
    {
        if ($exception instanceof OCSForbiddenException) {
            $params = ['message' => $exception->getMessage()];
            return new TemplateResponse('organization', 'errors/unauthorized', $params, 'guest');
        }
        throw $exception;
    }
}
