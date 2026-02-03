<?php
declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCA\Organization\Db\PlanMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;

class PlanController extends OCSController
{

    public function __construct(
        string $appName,
        IRequest $request,
        private PlanMapper $planMapper
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get all public plans.
     *
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function getPlans(): DataResponse
    {
        $plans = $this->planMapper->findAll();
        return new DataResponse(['plans' => $plans]);
    }
}
