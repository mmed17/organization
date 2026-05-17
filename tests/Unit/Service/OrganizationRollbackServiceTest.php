<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

use OCA\Organization\Service\OrganizationRollbackService;
use PHPUnit\Framework\TestCase;

class OrganizationRollbackServiceTest extends TestCase
{
    private OrganizationRollbackService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new \ReflectionClass(OrganizationRollbackService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
    }

    public function testBuildApplyValidationFailureResultPreservesPreviewDetails(): void
    {
        $result = $this->invokePrivate('buildApplyValidationFailureResult', [[
            'canApply' => false,
            'errors' => ['Archive organization does not match target organization'],
            'warnings' => ['Project 10 has no file entries in archive'],
            'impact' => [
                'members' => 4,
                'projects' => 2,
            ],
        ], 'apply', 77]);

        self::assertSame('apply', $result['mode']);
        self::assertSame(77, $result['sourceBackupJobId']);
        self::assertFalse($result['canApply']);
        self::assertSame(['Archive organization does not match target organization'], $result['validationErrors']);
        self::assertSame(['Project 10 has no file entries in archive'], $result['warnings']);
        self::assertSame(['members' => 4, 'projects' => 2], $result['impact']);
    }

    public function testBuildApplyValidationFailureResultDropsBlankMessages(): void
    {
        $result = $this->invokePrivate('buildApplyValidationFailureResult', [[
            'canApply' => false,
            'errors' => ['Primary error', '', '   ', 12],
            'warnings' => ['Warning', null],
            'impact' => 'invalid',
        ], 'apply', 88]);

        self::assertSame(['Primary error'], $result['validationErrors']);
        self::assertSame(['Warning'], $result['warnings']);
        self::assertSame([], $result['impact']);
    }

    public function testBuildApplyValidationFailureEventPayloadNormalizesStructure(): void
    {
        $payload = $this->invokePrivate('buildApplyValidationFailureEventPayload', [[
            'mode' => 'apply',
            'sourceBackupJobId' => 91,
            'canApply' => false,
            'validationErrors' => ['Referenced plan IDs are missing: 3'],
            'warnings' => ['Project 8 has no file entries in archive'],
            'impact' => ['projectFiles' => 12],
        ]]);

        self::assertSame('apply', $payload['mode']);
        self::assertSame(91, $payload['sourceBackupJobId']);
        self::assertFalse($payload['canApply']);
        self::assertSame(['Referenced plan IDs are missing: 3'], $payload['validationErrors']);
        self::assertSame(['Project 8 has no file entries in archive'], $payload['warnings']);
        self::assertSame(['projectFiles' => 12], $payload['impact']);
    }

    public function testDetermineProjectRestoreFolderPathPrefersArchivedFolderPath(): void
    {
        $path = $this->invokePrivate('determineProjectRestoreFolderPath', [[
            'folder_path' => '/Projects/Archived Migration/',
            'name' => 'Ignored Name',
        ]]);

        self::assertSame('Projects/Archived Migration', $path);
    }

    public function testDetermineProjectRestoreFolderPathFallsBackToProjectName(): void
    {
        $path = $this->invokePrivate('determineProjectRestoreFolderPath', [[
            'folder_path' => '',
            'name' => 'Restored Project',
        ]]);

        self::assertSame('Restored Project', $path);
    }

    /**
     * @param list<mixed> $args
     * @return mixed
     */
    private function invokePrivate(string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($this->service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->service, $args);
    }
}
