<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

use OCA\Organization\Service\OrganizationBackupService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrganizationBackupServiceReadableExportTest extends TestCase
{
    private OrganizationBackupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OrganizationBackupService(
            $this->createStub(IDBConnection::class),
            $this->createStub(IAppDataFactory::class),
            $this->createStub(IRootFolder::class),
            $this->createStub(ITempManager::class),
            $this->createStub(IConfig::class),
            $this->createStub(LoggerInterface::class),
        );
    }

    public function testEncodeJsonForExportUsesPrettyPrint(): void
    {
        $json = $this->invokePrivate('encodeJsonForExport', [[
            'organizationId' => 12,
            'paths' => [
                'files/projects/12/demo.txt',
            ],
        ]]);

        self::assertStringContainsString("{\n", $json);
        self::assertStringContainsString('    "organizationId": 12', $json);
        self::assertStringContainsString('"files/projects/12/demo.txt"', $json);
        self::assertStringEndsWith("\n", $json);
    }

    public function testBuildCsvStringEscapesComplexValues(): void
    {
        $csv = $this->invokePrivate('buildCsvString', [[
            [
                'name' => 'Alpha, Inc.',
                'notes' => "Line 1\nLine 2",
                'enabled' => true,
                'meta' => ['owner' => 'taha'],
                'missing' => null,
            ],
        ], ['name', 'notes', 'enabled', 'meta', 'missing']]);

        self::assertStringContainsString("name,notes,enabled,meta,missing\n", $csv);
        self::assertStringContainsString('"Alpha, Inc."', $csv);
        self::assertStringContainsString("\"Line 1\nLine 2\"", $csv);
        self::assertStringContainsString('1,"{""owner"":""taha""}",', $csv);
    }

    public function testBuildOverviewMarkdownIncludesReadableSections(): void
    {
        $markdown = $this->invokePrivate('buildOverviewMarkdown', [
            'org-3-backup-job-9.zip',
            'incremental',
            [
                'organizationId' => 3,
                'generatedAt' => '2026-04-07T09:00:00+00:00',
                'requestedByUid' => 'manager',
            ],
            [
                'members' => 4,
                'subscriptions' => 1,
                'projects' => 2,
                'deckBoards' => 1,
            ],
            ['Project 8 shared folder not found (folder_id=44)'],
            [
                'organization' => ['name' => 'Acme Org'],
                'subscriptions' => [['status' => 'active', 'plan_id' => 7, 'ended_at' => '2026-12-31']],
                'plans' => [['name' => 'Business']],
            ],
            [
                'projects' => [
                    ['id' => 8, 'name' => 'Migration', 'owner_id' => 'alice', 'board_id' => 22],
                ],
            ],
            [
                'boardIds' => [22],
            ],
            [
                'fileInventory' => [
                    ['path' => 'files/projects/8/Migration/spec.pdf', 'size' => 1024, 'projectId' => 8],
                ],
            ],
            [
                ['fileId' => 91, 'path' => 'files/projects/8/Migration/old.txt'],
            ],
        ]);

        self::assertStringContainsString('# Backup Overview', $markdown);
        self::assertStringContainsString('`Acme Org`', $markdown);
        self::assertStringContainsString('Members: 4', $markdown);
        self::assertStringContainsString('`Migration` (id: 8, owner: alice, board: 22)', $markdown);
        self::assertStringContainsString('project_notes_public.csv', $markdown);
        self::assertStringContainsString('project_notes_private.csv', $markdown);
        self::assertStringContainsString('deck/*.csv', $markdown);
        self::assertStringContainsString('files/file_inventory.csv', $markdown);
        self::assertStringContainsString('Project 8 shared folder not found', $markdown);
        self::assertStringContainsString('`files/projects/8/Migration/old.txt`', $markdown);
    }

    public function testBuildArchiveReadmeMentionsDeckCsvCompanions(): void
    {
        $markdown = $this->invokePrivate('buildArchiveReadmeMarkdown', [
            'org-7-backup-job-14.zip',
            'full',
        ]);

        self::assertStringContainsString('summary/overview.md', $markdown);
        self::assertStringContainsString('deck/*.csv', $markdown);
        self::assertStringContainsString('deck/boards/*.json', $markdown);
    }

    public function testBuildDeckCompanionCsvPayloadAggregatesBoardBundles(): void
    {
        $payload = $this->invokePrivate('buildDeckCompanionCsvPayload', [[
            'boardExports' => [
                [
                    'board' => ['id' => 22, 'title' => 'Main Board'],
                    'stacks' => [['id' => 100, 'board_id' => 22, 'title' => 'Todo']],
                    'cards' => [['id' => 300, 'stack_id' => 100, 'title' => 'Review backup']],
                    'labels' => [['id' => 400, 'board_id' => 22, 'title' => 'High']],
                    'boardAcl' => [['id' => 500, 'board_id' => 22, 'participant' => 'alice']],
                    'assignedUsers' => [['id' => 600, 'card_id' => 300, 'participant' => 'bob']],
                    'assignedLabels' => [['id' => 700, 'card_id' => 300, 'label_id' => 400]],
                    'attachments' => [['id' => 800, 'card_id' => 300, 'filename' => 'scope.pdf']],
                ],
                [
                    'board' => ['id' => 23, 'title' => 'Ops Board'],
                    'stacks' => [],
                    'cards' => [],
                    'labels' => [],
                    'boardAcl' => [],
                    'assignedUsers' => [],
                    'assignedLabels' => [],
                    'attachments' => [],
                ],
            ],
        ]]);

        self::assertArrayHasKey('deck/boards.csv', $payload);
        self::assertArrayHasKey('deck/cards.csv', $payload);
        self::assertArrayHasKey('deck/attachments.csv', $payload);
        self::assertCount(2, $payload['deck/boards.csv']);
        self::assertCount(1, $payload['deck/stacks.csv']);
        self::assertCount(1, $payload['deck/cards.csv']);
        self::assertCount(1, $payload['deck/labels.csv']);
        self::assertCount(1, $payload['deck/board_acl.csv']);
        self::assertCount(1, $payload['deck/assigned_users.csv']);
        self::assertCount(1, $payload['deck/assigned_labels.csv']);
        self::assertCount(1, $payload['deck/attachments.csv']);
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invokePrivate(string $methodName, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($this->service, $methodName);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->service, $arguments);
    }
}
