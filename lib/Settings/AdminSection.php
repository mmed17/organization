<?php

declare(strict_types=1);

namespace OCA\Organization\Settings;

use OCP\Settings\ISection;
use OCP\IURLGenerator;

class AdminSection implements ISection
{
	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string
	{
		return 'organization';
	}

	public function getName(): string
	{
		return 'Organization';
	}

	public function getPriority(): int
	{
		return 75;
	}

	public function getIcon(): string
	{
		return $this->urlGenerator->imagePath('organization', 'organization.svg');
	}
}
