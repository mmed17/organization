<?php

declare(strict_types=1);

namespace OCA\Organization\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\Organization\Middleware\SubscriptionMiddleware;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'organization';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        // Register the subscription middleware
        $context->registerMiddleware(SubscriptionMiddleware::class);
    }

    public function boot(IBootContext $context): void
    {
        // Boot logic if needed
    }
}
