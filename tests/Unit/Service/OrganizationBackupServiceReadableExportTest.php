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

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->with('default_timezone', 'UTC')->willReturn('UTC');

        $this->service = new OrganizationBackupService(
            $this->createStub(IDBConnection::class),
            $this->createStub(IAppDataFactory::class),
            $this->createStub(IRootFolder::class),
            $this->createStub(ITempManager::class),
            $config,
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

    public function testBuildOverviewMarkdownIncludesProjectFirstReadableSections(): void
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
                'subscriptions' => [['status' => 'active', 'plan_id' => 7, 'ended_at' => '2026-12-31 00:00:00']],
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
                ['fileId' => 91, 'projectId' => 8, 'path' => 'files/projects/8/Migration/old.txt'],
            ],
        ]);

        self::assertStringContainsString('# Backup Overview', $markdown);
        self::assertStringContainsString('`Acme Org`', $markdown);
        self::assertStringContainsString('Generated at: `2026-04-07 09:00:00`', $markdown);
        self::assertStringContainsString('`Migration` (id: 8, owner: alice, board: 22)', $markdown);
        self::assertStringContainsString('`readable/projects/`', $markdown);
        self::assertStringContainsString('`notes.csv`', $markdown);
        self::assertStringContainsString('deck_cards.csv', $markdown);
        self::assertStringContainsString('Project 8 shared folder not found', $markdown);
        self::assertStringContainsString('project 8', $markdown);
        self::assertStringNotContainsString('project_notes_public.csv', $markdown);
        self::assertStringNotContainsString('deck/*.csv', $markdown);
    }

    public function testBuildArchiveReadmeMentionsProjectFirstReadableExports(): void
    {
        $markdown = $this->invokePrivate('buildArchiveReadmeMarkdown', [
            'org-7-backup-job-14.zip',
            'full',
        ]);

        self::assertStringContainsString('summary/overview.md', $markdown);
        self::assertStringContainsString('readable/projects/index.csv', $markdown);
        self::assertStringContainsString('readable/projects/{project}/deck_cards.csv', $markdown);
        self::assertStringContainsString('deck/boards/*.json', $markdown);
        self::assertStringNotContainsString('deck/*.csv', $markdown);
    }

    public function testBuildProjectReadableCsvPayloadCreatesProjectBundleWithStackAndReadableTimes(): void
    {
        $payload = $this->invokePrivate('buildProjectReadableCsvPayload', [
            'incremental',
            [
                'projects' => [
                    [
                        'id' => 22,
                        'name' => 'Migration',
                        'owner_id' => 'alice',
                        'board_id' => 80,
                        'created_at' => '2026-04-01 08:30:00',
                    ],
                ],
                'notesPublic' => [
                    ['id' => 1, 'project_id' => 22, 'content' => 'Public note', 'created_at' => '2026-04-03 10:00:00'],
                ],
                'notesPrivate' => [
                    ['id' => 2, 'project_id' => 22, 'content' => 'Private note', 'created_at' => '2026-04-04 12:30:00'],
                ],
                'timeline' => [
                    ['id' => 10, 'project_id' => 22, 'created_at' => '2026-04-05 09:00:00'],
                ],
                'activity' => [
                    ['id' => 11, 'project_id' => 22, 'updated_at' => '2026-04-06 18:15:00'],
                ],
            ],
            [
                'boardExports' => [
                    [
                        'boardId' => 80,
                        'board' => ['id' => 80, 'title' => 'Main Board'],
                        'stacks' => [['id' => 100, 'board_id' => 80, 'title' => 'Todo']],
                        'cards' => [[
                            'id' => 300,
                            'stack_id' => 100,
                            'title' => 'Review backup',
                            'created_at' => '2026-04-02 11:00:00',
                            'duedate' => '2026-04-09 14:45:00',
                        ]],
                        'labels' => [['id' => 400, 'board_id' => 80, 'title' => 'High']],
                        'boardAcl' => [],
                        'assignedUsers' => [['id' => 600, 'card_id' => 300, 'participant' => 'bob']],
                        'assignedLabels' => [['id' => 700, 'card_id' => 300, 'label_id' => 400]],
                        'attachments' => [['id' => 800, 'card_id' => 300, 'filename' => 'scope.pdf']],
                    ],
                ],
            ],
            [
                'fileInventory' => [
                    ['fileId' => 900, 'projectId' => 22, 'path' => 'files/projects/22/Migration/spec.pdf', 'size' => 1024, 'mtime' => 1712570400, 'etag' => 'abc'],
                ],
            ],
            [
                ['fileId' => 901, 'projectId' => 22, 'path' => 'files/projects/22/Migration/old.txt', 'size' => 512, 'mtime' => 1712484000, 'etag' => 'def', 'jobId' => 77],
            ],
        ]);

        self::assertArrayHasKey('readable/projects/index.csv', $payload);
        self::assertArrayHasKey('readable/projects/22-Migration/summary.csv', $payload);
        self::assertArrayHasKey('readable/projects/22-Migration/notes.csv', $payload);
        self::assertArrayHasKey('readable/projects/22-Migration/deck_cards.csv', $payload);
        self::assertArrayHasKey('readable/projects/22-Migration/files.csv', $payload);
        self::assertArrayHasKey('readable/projects/22-Migration/deleted_files.csv', $payload);

        $cardRow = $payload['readable/projects/22-Migration/deck_cards.csv']['rows'][0];
        self::assertSame('Todo', $cardRow['stack_title']);
        self::assertSame('High', $cardRow['label_titles']);
        self::assertSame('bob', $cardRow['assigned_users']);
        self::assertSame('scope.pdf', $cardRow['attachment_filenames']);
        self::assertSame(1, $cardRow['attachment_count']);
        self::assertSame('2026-04-02 11:00:00', $cardRow['created_at']);
        self::assertSame('2026-04-09 14:45:00', $cardRow['duedate']);

        $noteRows = $payload['readable/projects/22-Migration/notes.csv']['rows'];
        self::assertCount(2, $noteRows);
        self::assertSame('public', $noteRows[0]['visibility']);
        self::assertSame('private', $noteRows[1]['visibility']);
        self::assertSame('2026-04-03 10:00:00', $noteRows[0]['created_at']);

        $fileRow = $payload['readable/projects/22-Migration/files.csv']['rows'][0];
        self::assertSame('2024-04-08 10:00:00', $fileRow['mtime']);

        $deletedFileRow = $payload['readable/projects/22-Migration/deleted_files.csv']['rows'][0];
        self::assertSame(22, $deletedFileRow['projectId']);
        self::assertSame('2024-04-07 10:00:00', $deletedFileRow['mtime']);
    }

    public function testBuildProjectReadableCsvPayloadOmitsDeletedFilesForFullBackup(): void
    {
        $payload = $this->invokePrivate('buildProjectReadableCsvPayload', [
            'full',
            [
                'projects' => [
                    ['id' => 5, 'name' => 'Ops', 'owner_id' => 'bob', 'board_id' => null],
                ],
            ],
            [
                'boardExports' => [],
            ],
            [
                'fileInventory' => [],
            ],
            [
                ['fileId' => 99, 'projectId' => 5, 'path' => 'files/projects/5/Ops/old.txt', 'mtime' => 1712484000, 'jobId' => 7],
            ],
        ]);

        self::assertArrayHasKey('readable/projects/5-Ops/files.csv', $payload);
        self::assertArrayNotHasKey('readable/projects/5-Ops/deleted_files.csv', $payload);
    }

    public function testReadableTimeFormattingUsesPlainReadableStrings(): void
    {
        $formattedDateTime = $this->invokePrivate('formatReadableDateTimeValue', ['2026-04-21T14:30:00+00:00']);
        $formattedUnix = $this->invokePrivate('formatReadableUnixTimestamp', [1712570400]);
        $formattedInvalid = $this->invokePrivate('formatReadableDateTimeValue', ['not-a-date']);

        self::assertSame('2026-04-21 14:30:00', $formattedDateTime);
        self::assertSame('2024-04-08 10:00:00', $formattedUnix);
        self::assertSame('not-a-date', $formattedInvalid);
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
