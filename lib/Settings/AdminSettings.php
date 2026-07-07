<?php

declare(strict_types=1);

namespace OCA\Organization\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\AppFramework\Services\IInitialState;

use OCA\Organization\Service\TrialOrganizationService;

class AdminSettings implements ISettings
{
	public function __construct(
		private TrialOrganizationService $trialService,
		private IInitialState $initialState,
	) {
	}

	public function getForm(): TemplateResponse
	{
		$this->initialState->provideInitialState(
			'trial_settings',
			$this->trialService->getAllSettings(),
		);

		return new TemplateResponse('organization', 'settings/admin', [], '');
	}

	public function getSection(): ?string
	{
		return 'organization';
	}

	public function getPriority(): int
	{
		return 50;
	}
}
