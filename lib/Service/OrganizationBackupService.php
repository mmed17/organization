<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\ITempManager;

use ZipStreamer\COMPR;
use ZipStreamer\ZipStreamer;

use Psr\Log\LoggerInterface;

class OrganizationBackupService
{
    private const BACKUP_FORMAT_VERSION = 2;
    private const JOBS_TABLE = 'org_backup_jobs';
    private const STEPS_TABLE = 'org_backup_steps';
    private const EVENTS_TABLE = 'org_backup_events';
    private const FILE_INDEX_TABLE = 'org_backup_file_index';

    private const BACKUP_FOLDER = 'org-backups';
    private const BACKUP_TYPE_FULL = 'full';
    private const BACKUP_TYPE_INCREMENTAL = 'incremental';
    private const TRIGGER_MANUAL = 'manual';
    private const TRIGGER_SCHEDULED = 'scheduled';
    private const RETENTION_JOBS = 7;
    private const SCHEDULE_DAILY_TIME = '02:00';
    private const SCHEDULE_WEEKLY_TIME = '03:00';

    /** @var list<string> */
    private const STEP_ORDER = ['collect_db', 'export_deck', 'export_files', 'finalize'];
    /** @var list<string> */
    private const DECK_COMPANION_CSV_FILES = [
        'deck/boards.csv',
        'deck/stacks.csv',
        'deck/cards.csv',
        'deck/labels.csv',
        'deck/board_acl.csv',
        'deck/assigned_users.csv',
        'deck/assigned_labels.csv',
        'deck/attachments.csv',
    ];

    public function __construct(
        private IDBConnection $db,
        private IAppDataFactory $appDataFactory,
        private IRootFolder $rootFolder,
        private ITempManager $tempManager,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function createJob(
        int $organizationId,
        string $requestedByUid,
        array $options = [],
        string $backupType = self::BACKUP_TYPE_FULL,
        string $triggerSource = self::TRIGGER_MANUAL,
        ?int $baselineJobId = null,
        ?int $baseFullJobId = null,
        ?string $scheduleKey = null,
    ): array
    {
        if ($organizationId <= 0) {
            throw new \InvalidArgumentException('organizationId must be a positive integer');
        }

        $requestedByUid = trim($requestedByUid);
        if ($requestedByUid === '') {
            throw new \InvalidArgumentException('requestedByUid must not be empty');
        }

        $backupType = $this->normalizeBackupType($backupType);
        $triggerSource = $this->normalizeTriggerSource($triggerSource);
        $scheduleKey = $scheduleKey !== null ? trim($scheduleKey) : null;
        if ($scheduleKey === '') {
            $scheduleKey = null;
        }

        $now = $this->utcNow();
        $expiresAt = $this->utcNowPlusSeconds(24 * 60 * 60);

        $insert = $this->db->getQueryBuilder();
        $insert->insert(self::JOBS_TABLE)
            ->values([
                'organization_id' => $insert->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT),
                'requested_by_uid' => $insert->createNamedParameter($requestedByUid, IQueryBuilder::PARAM_STR),
                'backup_type' => $insert->createNamedParameter($backupType, IQueryBuilder::PARAM_STR),
                'trigger_source' => $insert->createNamedParameter($triggerSource, IQueryBuilder::PARAM_STR),
                'baseline_job_id' => $insert->createNamedParameter($baselineJobId),
                'base_full_job_id' => $insert->createNamedParameter($baseFullJobId),
                'schedule_key' => $insert->createNamedParameter($scheduleKey),
                'status' => $insert->createNamedParameter('queued', IQueryBuilder::PARAM_STR),
                'options_json' => $insert->createNamedParameter(json_encode($options, JSON_THROW_ON_ERROR), IQueryBuilder::PARAM_STR),
                'attempt' => $insert->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                'result_json' => $insert->createNamedParameter(null),
                'error_message' => $insert->createNamedParameter(null),
                'artifact_name' => $insert->createNamedParameter(null),
                'artifact_size' => $insert->createNamedParameter(null),
                'created_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_STR),
                'updated_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_STR),
                'started_at' => $insert->createNamedParameter(null),
                'finished_at' => $insert->createNamedParameter(null),
                'expires_at' => $insert->createNamedParameter($expiresAt, IQueryBuilder::PARAM_STR),
            ])
            ->executeStatement();

        $jobId = (int) $insert->getLastInsertId();
        if ($jobId <= 0) {
            throw new \RuntimeException('Failed to create backup job');
        }

        foreach (self::STEP_ORDER as $stepKey) {
            $this->upsertStep($jobId, $stepKey, [
                'status' => 'queued',
                'attempt' => 1,
                'retriable' => true,
                'error_message' => null,
                'result_json' => null,
                'started_at' => null,
                'finished_at' => null,
                'updated_at' => $now,
            ]);
        }

        $this->insertEvent($jobId, 'info', 'Organization backup job queued', [
            'organizationId' => $organizationId,
            'requestedByUid' => $requestedByUid,
            'backupType' => $backupType,
            'triggerSource' => $triggerSource,
            'baselineJobId' => $baselineJobId,
            'baseFullJobId' => $baseFullJobId,
            'scheduleKey' => $scheduleKey,
            'expiresAt' => $expiresAt,
        ]);

        $row = $this->getJobRowById($jobId);
        if ($row === null) {
            throw new \RuntimeException('Failed to load created job');
        }

        return $this->mapJobRow($row, true);
    }

    public function getOldestQueuedJobId(): ?int
    {
        try {
            $this->cleanupExpired();
        } catch (\Throwable $e) {
            $this->logger->error('Backup cleanup failed before queue fetch', [
                'exception' => $e,
            ]);
        }

        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('queued')))
            ->orderBy('id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery();

        $jobId = $result->fetchOne();
        $result->closeCursor();

        return $jobId === false ? null : (int) $jobId;
    }

    public function enqueueScheduledJobs(): void
    {
        $timeZone = $this->resolveInstanceTimeZone();
        $nowLocal = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($timeZone);
        $scheduleKey = $nowLocal->format('Y-m-d');

        $isSunday = (int) $nowLocal->format('N') === 7;
        if ($isSunday) {
            if ($nowLocal->format('H:i') < self::SCHEDULE_WEEKLY_TIME) {
                return;
            }
            $scheduledType = self::BACKUP_TYPE_FULL;
        } else {
            if ($nowLocal->format('H:i') < self::SCHEDULE_DAILY_TIME) {
                return;
            }
            $scheduledType = self::BACKUP_TYPE_INCREMENTAL;
        }

        try {
            $organizationIds = $this->listOrganizationIds();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list organizations for scheduled backups', [
                'exception' => $e,
            ]);
            return;
        }

        foreach ($organizationIds as $organizationId) {
            $resolvedType = $scheduledType;
            $baselineJobId = $this->findLatestCompletedJobId($organizationId);
            if ($scheduledType === self::BACKUP_TYPE_INCREMENTAL && $baselineJobId === null) {
                $resolvedType = self::BACKUP_TYPE_FULL;
            }

            $baseFullJobId = $this->findLatestCompletedJobIdByType($organizationId, self::BACKUP_TYPE_FULL);

            if ($this->hasScheduledJobForScheduleKey($organizationId, $scheduleKey)) {
                continue;
            }

            if ($this->hasInProgressJobOfType($organizationId, $resolvedType)) {
                continue;
            }

            try {
                $this->createJob(
                    $organizationId,
                    '__system__',
                    [
                        'includeProjectCreator' => true,
                        'includeDeck' => true,
                        'includeSharedFiles' => true,
                        'excludePrivateData' => true,
                    ],
                    $resolvedType,
                    self::TRIGGER_SCHEDULED,
                    $baselineJobId,
                    $baseFullJobId,
                    $scheduleKey,
                );
            } catch (\Throwable $e) {
                $this->logger->error('Failed to enqueue scheduled backup job', [
                    'exception' => $e,
                    'organizationId' => $organizationId,
                    'backupType' => $resolvedType,
                    'scheduleKey' => $scheduleKey,
                ]);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function runJob(int $jobId): array
    {
        $job = $this->getJobRowById($jobId);
        if ($job === null) {
            throw new \InvalidArgumentException('Job not found');
        }

        $status = (string) ($job['status'] ?? '');
        if (!in_array($status, ['queued', 'failed'], true)) {
            return $this->mapJobRow($job, true);
        }

        if ($this->isExpiredJobRow($job)) {
            $this->expireJob($jobId, $job);
            $expired = $this->getJobRowById($jobId);
            if ($expired === null) {
                throw new \RuntimeException('Failed to load expired job');
            }
            return $this->mapJobRow($expired, true);
        }

        $now = $this->utcNow();
        $this->updateJob($jobId, [
            'status' => 'running',
            'updated_at' => $now,
            'started_at' => $job['started_at'] ?? $now,
            'finished_at' => null,
            'error_message' => null,
            'result_json' => null,
            'artifact_name' => null,
            'artifact_size' => null,
        ]);
        $this->insertEvent($jobId, 'info', 'Backup execution started');

        try {
            $organizationId = (int) $job['organization_id'];
            $requestedByUid = (string) $job['requested_by_uid'];
            $options = $this->decodeJsonNullable($job['options_json']);
            $options = is_array($options) ? $options : [];
            $backupType = $this->normalizeBackupType((string) ($job['backup_type'] ?? self::BACKUP_TYPE_FULL));
            $triggerSource = $this->normalizeTriggerSource((string) ($job['trigger_source'] ?? self::TRIGGER_MANUAL));
            $baselineJobId = isset($job['baseline_job_id']) ? (int) $job['baseline_job_id'] : null;
            $baseFullJobId = isset($job['base_full_job_id']) ? (int) $job['base_full_job_id'] : null;

            if ($backupType === self::BACKUP_TYPE_INCREMENTAL) {
                if ($baselineJobId === null || $baselineJobId <= 0) {
                    $baselineJobId = $this->findLatestCompletedJobId($organizationId);
                }
                if ($baseFullJobId === null || $baseFullJobId <= 0) {
                    $baseFullJobId = $this->findLatestCompletedJobIdByType($organizationId, self::BACKUP_TYPE_FULL);
                }

                if ($baselineJobId === null || $baselineJobId <= 0) {
                    $backupType = self::BACKUP_TYPE_FULL;
                    $this->insertEvent($jobId, 'info', 'Incremental backup upgraded to full backup', [
                        'reason' => 'No previous successful backup baseline',
                    ]);
                }
            }

            $this->updateJob($jobId, [
                'backup_type' => $backupType,
                'trigger_source' => $triggerSource,
                'baseline_job_id' => $baselineJobId,
                'base_full_job_id' => $baseFullJobId,
                'updated_at' => $this->utcNow(),
            ]);

            $artifactName = $this->buildArtifactName($organizationId, $jobId);

            $this->markStepRunning($jobId, 'collect_db');
            $summary = [
                'formatVersion' => self::BACKUP_FORMAT_VERSION,
                'generatedAt' => $this->utcNowIso8601(),
                'organizationId' => $organizationId,
                'requestedByUid' => $requestedByUid,
                'backupType' => $backupType,
                'triggerSource' => $triggerSource,
                'baselineJobId' => $baselineJobId,
                'baseFullJobId' => $baseFullJobId,
                'options' => $options,
            ];
            $this->markStepCompleted($jobId, 'collect_db', ['status' => 'completed']);

            $this->markStepRunning($jobId, 'export_deck');
            $deckSummary = $this->collectDeckScopeSummary($organizationId);
            $summary['deck'] = $deckSummary;
            $this->markStepCompleted($jobId, 'export_deck', ['status' => 'completed'] + $deckSummary);

            $this->markStepRunning($jobId, 'export_files');
            $filesSummary = $this->collectFilesScopeSummary($organizationId);
            $summary['files'] = $filesSummary;
            $this->markStepCompleted($jobId, 'export_files', ['status' => 'completed'] + $filesSummary);

            $this->markStepRunning($jobId, 'finalize');
            $tmpPath = $this->createTempFilePath('.zip');
            $zipResult = $this->generateZipToPath($organizationId, $jobId, $backupType, $tmpPath, $artifactName, $summary);
            $fileIndexEntries = isset($zipResult['fileIndexEntries']) && is_array($zipResult['fileIndexEntries']) ? $zipResult['fileIndexEntries'] : [];
            unset($zipResult['fileIndexEntries']);
            $artifactSize = filesize($tmpPath);
            if ($artifactSize === false) {
                $artifactSize = null;
            }

            $this->storeArtifact($organizationId, $artifactName, $tmpPath);
            @unlink($tmpPath);

            $completedAt = $this->utcNow();
            $result = [
                'artifactName' => $artifactName,
                'artifactSize' => $artifactSize,
                'expiresAt' => (string) $job['expires_at'],
                'summary' => $zipResult,
            ];

            $this->updateJob($jobId, [
                'status' => 'completed',
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
                'error_message' => null,
                'artifact_name' => $artifactName,
                'artifact_size' => $artifactSize,
                'finished_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);
            $this->insertEvent($jobId, 'info', 'Backup completed', [
                'artifactName' => $artifactName,
                'artifactSize' => $artifactSize,
            ]);
            $this->markStepCompleted($jobId, 'finalize', $result);
            $this->replaceFileIndexSnapshot($organizationId, $jobId, $fileIndexEntries);
            $this->enforceRetentionLimit($organizationId, self::RETENTION_JOBS);

            $jobRow = $this->getJobRowById($jobId);
            if ($jobRow === null) {
                throw new \RuntimeException('Failed to load completed job');
            }

            return $this->mapJobRow($jobRow, true);
        } catch (\Throwable $e) {
            $failedAt = $this->utcNow();
            $this->updateJob($jobId, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => $failedAt,
                'updated_at' => $failedAt,
            ]);
            $this->insertEvent($jobId, 'error', 'Backup failed', [
                'error' => $e->getMessage(),
            ]);

            $this->logger->error('Organization backup failed', [
                'exception' => $e,
                'jobId' => $jobId,
            ]);

            $jobRow = $this->getJobRowById($jobId);
            if ($jobRow === null) {
                throw new \RuntimeException('Failed to load failed job');
            }

            return $this->mapJobRow($jobRow, true);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function getJob(int $organizationId, int $jobId): array
    {
        $row = $this->getJobRowByIdAndOrganization($jobId, $organizationId);
        if ($row === null) {
            throw new \InvalidArgumentException('Job not found');
        }

        return $this->mapJobRow($row, true);
    }

    /**
     * @return array{jobs:list<array<string,mixed>>,limit:int,offset:int}
     */
    public function listJobs(int $organizationId, ?string $status, int $limit, int $offset): array
    {
        $effectiveLimit = max(1, min($limit, 100));
        $effectiveOffset = max(0, $offset);
        $effectiveStatus = $status === null ? '' : trim($status);

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)));

        if ($effectiveStatus !== '') {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($effectiveStatus)));
        }

        $result = $qb->orderBy('id', 'DESC')
            ->setMaxResults($effectiveLimit)
            ->setFirstResult($effectiveOffset)
            ->executeQuery();

        $jobs = [];
        while (($row = $result->fetch()) !== false) {
            $jobs[] = $this->mapJobRow($row, false);
        }
        $result->closeCursor();

        return [
            'jobs' => $jobs,
            'limit' => $effectiveLimit,
            'offset' => $effectiveOffset,
        ];
    }

    /**
     * @return array{events:list<array<string,mixed>>,limit:int,offset:int}
     */
    public function listEvents(int $organizationId, int $jobId, int $limit, int $offset): array
    {
        $row = $this->getJobRowByIdAndOrganization($jobId, $organizationId);
        if ($row === null) {
            throw new \InvalidArgumentException('Job not found');
        }

        $effectiveLimit = max(1, min($limit, 200));
        $effectiveOffset = max(0, $offset);

        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::EVENTS_TABLE)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->orderBy('sequence_no', 'ASC')
            ->setMaxResults($effectiveLimit)
            ->setFirstResult($effectiveOffset)
            ->executeQuery();

        $events = [];
        while (($eventRow = $result->fetch()) !== false) {
            $events[] = $this->mapEventRow($eventRow);
        }
        $result->closeCursor();

        return [
            'events' => $events,
            'limit' => $effectiveLimit,
            'offset' => $effectiveOffset,
        ];
    }

    public function deleteJob(int $organizationId, int $jobId): void
    {
        $row = $this->getJobRowByIdAndOrganization($jobId, $organizationId);
        if ($row === null) {
            throw new \InvalidArgumentException('Job not found');
        }

        $artifactName = isset($row['artifact_name']) ? (string) $row['artifact_name'] : '';
        if ($artifactName !== '') {
            $this->deleteArtifactIfExists($organizationId, $artifactName);
        }

        $now = $this->utcNow();
        $this->updateJob($jobId, [
            'status' => 'deleted',
            'updated_at' => $now,
            'finished_at' => $row['finished_at'] ?? $now,
            'artifact_name' => null,
            'artifact_size' => null,
        ]);
        $this->insertEvent($jobId, 'info', 'Backup artifact deleted', [
            'previousArtifactName' => $artifactName !== '' ? $artifactName : null,
        ]);
    }

    public function cleanupExpired(): void
    {
        $now = $this->utcNow();

        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($now)))
            ->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(['expired', 'deleted'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('id', 'ASC')
            ->setMaxResults(50)
            ->executeQuery();

        while (($row = $result->fetch()) !== false) {
            $jobId = (int) ($row['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }
            $organizationId = (int) ($row['organization_id'] ?? 0);
            $artifactName = isset($row['artifact_name']) ? (string) $row['artifact_name'] : '';
            if ($organizationId > 0 && $artifactName !== '') {
                $this->deleteArtifactIfExists($organizationId, $artifactName);
            }
            $this->updateJob($jobId, [
                'status' => 'expired',
                'updated_at' => $now,
                'finished_at' => $row['finished_at'] ?? $now,
                'artifact_name' => null,
                'artifact_size' => null,
            ]);
            $this->insertEvent($jobId, 'info', 'Backup expired', [
                'previousArtifactName' => $artifactName !== '' ? $artifactName : null,
            ]);
        }
        $result->closeCursor();

        try {
            $this->cleanupRetentionPolicy();
        } catch (\Throwable $e) {
            $this->logger->error('Backup retention cleanup failed', [
                'exception' => $e,
            ]);
        }
    }

    private function cleanupRetentionPolicy(): void
    {
        foreach ($this->listOrganizationsWithBackupJobs() as $organizationId) {
            $this->enforceRetentionLimit($organizationId, self::RETENTION_JOBS);
        }
    }

    /**
     * @return list<int>
     */
    private function listOrganizationsWithBackupJobs(): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->selectDistinct('organization_id')
            ->from(self::JOBS_TABLE)
            ->executeQuery();

        $organizationIds = [];
        while (($row = $result->fetch()) !== false) {
            $organizationId = isset($row['organization_id']) ? (int) $row['organization_id'] : 0;
            if ($organizationId > 0) {
                $organizationIds[] = $organizationId;
            }
        }
        $result->closeCursor();

        return $organizationIds;
    }

    private function enforceRetentionLimit(int $organizationId, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(['queued', 'running'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setFirstResult($keep)
            ->executeQuery();

        while (($row = $result->fetch()) !== false) {
            if (!$this->purgeJobHistory($row, $organizationId)) {
                continue;
            }
        }
        $result->closeCursor();
    }

    /**
     * @param array<string,mixed> $row
     */
    private function purgeJobHistory(array $row, int $organizationId): bool
    {
        $jobId = isset($row['id']) ? (int) $row['id'] : 0;
        if ($jobId <= 0) {
            return false;
        }

        $artifactName = isset($row['artifact_name']) ? (string) $row['artifact_name'] : '';
        if ($artifactName !== '') {
            $this->deleteArtifactIfExists($organizationId, $artifactName);
        }

        $this->db->beginTransaction();

        try {
            $deleteEvents = $this->db->getQueryBuilder();
            $deleteEvents->delete(self::EVENTS_TABLE)
                ->where($deleteEvents->expr()->eq('job_id', $deleteEvents->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $deleteSteps = $this->db->getQueryBuilder();
            $deleteSteps->delete(self::STEPS_TABLE)
                ->where($deleteSteps->expr()->eq('job_id', $deleteSteps->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $deleteJob = $this->db->getQueryBuilder();
            $deleteJob->delete(self::JOBS_TABLE)
                ->where($deleteJob->expr()->eq('id', $deleteJob->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->db->commit();

            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function openArtifactStream(int $organizationId, string $artifactName)
    {
        $appData = $this->appDataFactory->get('organization');
        $folder = $this->getFolderPath($appData, [
            self::BACKUP_FOLDER,
            'org-' . $organizationId,
        ], false);

        $file = $folder->getFile($artifactName);
        if (method_exists($file, 'read')) {
            $stream = $file->read();
            if (is_resource($stream)) {
                return $stream;
            }
        }

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open temporary artifact stream');
        }

        $content = $file->getContent();
        if (fwrite($stream, $content) === false) {
            fclose($stream);
            throw new \RuntimeException('Failed to write artifact to temporary stream');
        }

        rewind($stream);

        return $stream;
    }

    public function artifactExists(int $organizationId, string $artifactName): bool
    {
        $appData = $this->appDataFactory->get('organization');
        try {
            $folder = $this->getFolderPath($appData, [
                self::BACKUP_FOLDER,
                'org-' . $organizationId,
            ], false);
        } catch (\Throwable) {
            return false;
        }

        return $folder->fileExists($artifactName);
    }

    public function markJobPickedByWorker(int $jobId): void
    {
        // Intentionally no-op: worker pick-up is noisy in activity logs.
    }

    public function markJobFailedFromWorker(int $jobId, \Throwable $e): void
    {
        $failedAt = $this->utcNow();
        try {
            $this->updateJob($jobId, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => $failedAt,
                'updated_at' => $failedAt,
            ]);
            $this->insertEvent($jobId, 'error', 'Backup failed before execution start', [
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $inner) {
            $this->logger->error('Failed to mark backup job as failed after worker exception', [
                'exception' => $inner,
                'jobId' => $jobId,
            ]);
        }

        $this->logger->error('Backup worker execution failed', [
            'exception' => $e,
            'jobId' => $jobId,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function mapJobRow(array $row, bool $includeSteps): array
    {
        $jobId = (int) $row['id'];

        return [
            'jobId' => $jobId,
            'organizationId' => (int) $row['organization_id'],
            'requestedByUid' => (string) $row['requested_by_uid'],
            'backupType' => $this->normalizeBackupType((string) ($row['backup_type'] ?? self::BACKUP_TYPE_FULL)),
            'triggerSource' => $this->normalizeTriggerSource((string) ($row['trigger_source'] ?? self::TRIGGER_MANUAL)),
            'baselineJobId' => isset($row['baseline_job_id']) ? (int) $row['baseline_job_id'] : null,
            'baseFullJobId' => isset($row['base_full_job_id']) ? (int) $row['base_full_job_id'] : null,
            'scheduleKey' => $row['schedule_key'] ?? null,
            'status' => (string) $row['status'],
            'attempt' => (int) ($row['attempt'] ?? 1),
            'options' => $this->decodeJsonNullable($row['options_json']),
            'result' => $this->decodeJsonNullable($row['result_json']),
            'errorMessage' => $row['error_message'],
            'artifactName' => $row['artifact_name'],
            'artifactSize' => $row['artifact_size'] !== null ? (int) $row['artifact_size'] : null,
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'startedAt' => $row['started_at'],
            'finishedAt' => $row['finished_at'],
            'expiresAt' => $row['expires_at'],
            'steps' => $includeSteps ? $this->listMappedStepsByJobId($jobId) : [],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listMappedStepsByJobId(int $jobId): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::STEPS_TABLE)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')
            ->executeQuery();

        $steps = [];
        while (($row = $result->fetch()) !== false) {
            $steps[] = [
                'id' => (int) $row['id'],
                'jobId' => (int) $row['job_id'],
                'stepKey' => (string) $row['step_key'],
                'status' => (string) $row['status'],
                'attempt' => (int) $row['attempt'],
                'retriable' => ((int) $row['retriable']) === 1,
                'result' => $this->decodeJsonNullable($row['result_json']),
                'errorMessage' => $row['error_message'],
                'startedAt' => $row['started_at'],
                'finishedAt' => $row['finished_at'],
                'updatedAt' => $row['updated_at'],
            ];
        }
        $result->closeCursor();

        return $steps;
    }

    /**
     * @return array<string,mixed>
     */
    private function mapEventRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'jobId' => (int) $row['job_id'],
            'sequenceNo' => (int) ($row['sequence_no'] ?? 1),
            'stepKey' => $row['step_key'],
            'level' => (string) $row['level'],
            'message' => (string) $row['message'],
            'payload' => $this->decodeJsonNullable($row['payload_json']),
            'createdAt' => $row['created_at'],
        ];
    }

    private function utcNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function utcNowIso8601(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }

    private function utcNowPlusSeconds(int $seconds): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->add(new \DateInterval(sprintf('PT%dS', max(0, $seconds))))
            ->format('Y-m-d H:i:s');
    }

    private function normalizeBackupType(string $backupType): string
    {
        $backupType = strtolower(trim($backupType));
        if ($backupType === self::BACKUP_TYPE_INCREMENTAL) {
            return self::BACKUP_TYPE_INCREMENTAL;
        }
        return self::BACKUP_TYPE_FULL;
    }

    private function normalizeTriggerSource(string $triggerSource): string
    {
        $triggerSource = strtolower(trim($triggerSource));
        if ($triggerSource === self::TRIGGER_SCHEDULED) {
            return self::TRIGGER_SCHEDULED;
        }
        return self::TRIGGER_MANUAL;
    }

    private function resolveInstanceTimeZone(): \DateTimeZone
    {
        $name = 'UTC';
        try {
            $value = $this->config->getSystemValue('default_timezone', 'UTC');
            if (is_string($value) && trim($value) !== '') {
                $name = trim($value);
            }
        } catch (\Throwable) {
        }

        try {
            return new \DateTimeZone($name);
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * @return list<int>
     */
    private function listOrganizationIds(): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from('organizations')
            ->orderBy('id', 'ASC')
            ->executeQuery();

        $organizationIds = [];
        while (($row = $result->fetch()) !== false) {
            $organizationId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($organizationId > 0) {
                $organizationIds[] = $organizationId;
            }
        }
        $result->closeCursor();

        return $organizationIds;
    }

    private function hasScheduledJobForScheduleKey(int $organizationId, string $scheduleKey): bool
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('trigger_source', $qb->createNamedParameter(self::TRIGGER_SCHEDULED)))
            ->andWhere($qb->expr()->eq('schedule_key', $qb->createNamedParameter($scheduleKey)))
            ->setMaxResults(1)
            ->executeQuery();

        $id = $result->fetchOne();
        $result->closeCursor();

        return $id !== false;
    }

    private function hasInProgressJobOfType(int $organizationId, string $backupType): bool
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('backup_type', $qb->createNamedParameter($backupType)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['queued', 'running'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->setMaxResults(1)
            ->executeQuery();

        $id = $result->fetchOne();
        $result->closeCursor();

        return $id !== false;
    }

    private function findLatestCompletedJobId(int $organizationId): ?int
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('completed')))
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery();

        $id = $result->fetchOne();
        $result->closeCursor();

        return $id === false ? null : (int) $id;
    }

    private function findLatestCompletedJobIdByType(int $organizationId, string $backupType): ?int
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('completed')))
            ->andWhere($qb->expr()->eq('backup_type', $qb->createNamedParameter($backupType)))
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery();

        $id = $result->fetchOne();
        $result->closeCursor();

        return $id === false ? null : (int) $id;
    }

    private function buildArtifactName(int $organizationId, int $jobId): string
    {
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd_His');
        return sprintf('org-%d-backup-job-%d-%s.zip', $organizationId, $jobId, $stamp);
    }

    private function createTempFilePath(string $suffix): string
    {
        $tmp = $this->tempManager->getTemporaryFile($suffix);
        if ($tmp === false) {
            throw new \RuntimeException('Failed to allocate temporary file');
        }
        return (string) $tmp;
    }

    /**
     * @param array<string,mixed> $values
     */
    private function updateJob(int $jobId, array $values): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::JOBS_TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)));

        foreach ($values as $column => $value) {
            $qb->set($column, $qb->createNamedParameter($value));
        }

        $qb->executeStatement();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getJobRowById(int $jobId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getJobRowByIdAndOrganization(int $jobId, int $organizationId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    private function isExpiredJobRow(array $row): bool
    {
        $expiresAt = (string) ($row['expires_at'] ?? '');
        if ($expiresAt === '') {
            return false;
        }

        try {
            $expires = new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return false;
        }

        return $expires < new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function expireJob(int $jobId, array $row): void
    {
        $organizationId = (int) ($row['organization_id'] ?? 0);
        $artifactName = isset($row['artifact_name']) ? (string) $row['artifact_name'] : '';
        if ($organizationId > 0 && $artifactName !== '') {
            $this->deleteArtifactIfExists($organizationId, $artifactName);
        }

        $now = $this->utcNow();
        $this->updateJob($jobId, [
            'status' => 'expired',
            'updated_at' => $now,
            'finished_at' => $row['finished_at'] ?? $now,
            'artifact_name' => null,
            'artifact_size' => null,
        ]);
        $this->insertEvent($jobId, 'info', 'Backup expired', [
            'previousArtifactName' => $artifactName !== '' ? $artifactName : null,
        ]);
    }

    private function insertEvent(int $jobId, string $level, string $message, ?array $payload = null, ?string $stepKey = null): void
    {
        $now = $this->utcNow();
        $sequenceNo = $this->nextEventSequenceNo($jobId);

        $insert = $this->db->getQueryBuilder();
        $insert->insert(self::EVENTS_TABLE)
            ->values([
                'job_id' => $insert->createNamedParameter($jobId, IQueryBuilder::PARAM_INT),
                'sequence_no' => $insert->createNamedParameter($sequenceNo, IQueryBuilder::PARAM_INT),
                'step_key' => $insert->createNamedParameter($stepKey),
                'level' => $insert->createNamedParameter($level),
                'message' => $insert->createNamedParameter($message),
                'payload_json' => $insert->createNamedParameter($payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : null),
                'created_at' => $insert->createNamedParameter($now),
            ])
            ->executeStatement();
    }

    private function nextEventSequenceNo(int $jobId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('MAX(sequence_no)'), 'max_seq')
            ->from(self::EVENTS_TABLE)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $max = $result->fetchOne();
        $result->closeCursor();

        return ((int) $max) + 1;
    }

    /**
     * @param array<string,mixed> $values
     */
    private function upsertStep(int $jobId, string $stepKey, array $values): void
    {
        $existing = $this->getStepRow($jobId, $stepKey);
        $qb = $this->db->getQueryBuilder();

        if ($existing === null) {
            $qb->insert(self::STEPS_TABLE)
                ->values([
                    'job_id' => $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT),
                    'step_key' => $qb->createNamedParameter($stepKey, IQueryBuilder::PARAM_STR),
                    'status' => $qb->createNamedParameter($values['status'] ?? 'queued'),
                    'attempt' => $qb->createNamedParameter((int) ($values['attempt'] ?? 1), IQueryBuilder::PARAM_INT),
                    'retriable' => $qb->createNamedParameter((int) ((bool) ($values['retriable'] ?? true)), IQueryBuilder::PARAM_INT),
                    'result_json' => $qb->createNamedParameter($values['result_json'] ?? null),
                    'error_message' => $qb->createNamedParameter($values['error_message'] ?? null),
                    'started_at' => $qb->createNamedParameter($values['started_at'] ?? null),
                    'finished_at' => $qb->createNamedParameter($values['finished_at'] ?? null),
                    'updated_at' => $qb->createNamedParameter($values['updated_at'] ?? $this->utcNow()),
                ])
                ->executeStatement();

            return;
        }

        $qb->update(self::STEPS_TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $existing['id'], IQueryBuilder::PARAM_INT)));

        foreach ($values as $column => $value) {
            if ($column === 'result_json' && is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
            if ($column === 'attempt') {
                $qb->set($column, $qb->createNamedParameter((int) $value, IQueryBuilder::PARAM_INT));
                continue;
            }
            if ($column === 'retriable') {
                $qb->set($column, $qb->createNamedParameter((int) ((bool) $value), IQueryBuilder::PARAM_INT));
                continue;
            }

            $qb->set($column, $qb->createNamedParameter($value));
        }

        $qb->executeStatement();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getStepRow(int $jobId, string $stepKey): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::STEPS_TABLE)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('step_key', $qb->createNamedParameter($stepKey)))
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    private function markStepRunning(int $jobId, string $stepKey): void
    {
        $now = $this->utcNow();
        $this->upsertStep($jobId, $stepKey, [
            'status' => 'running',
            'error_message' => null,
            'started_at' => $now,
            'finished_at' => null,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private function markStepCompleted(int $jobId, string $stepKey, ?array $result = null): void
    {
        $now = $this->utcNow();
        $this->upsertStep($jobId, $stepKey, [
            'status' => 'completed',
            'retriable' => false,
            'result_json' => $result !== null ? json_encode($result, JSON_THROW_ON_ERROR) : null,
            'error_message' => null,
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param mixed $value
     */
    private function decodeJsonNullable($value)
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function collectDeckScopeSummary(int $organizationId): array
    {
        try {
            $boardIds = $this->findDeckBoardIdsForOrganization($organizationId);
            return [
                'boardCount' => count($boardIds),
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('Backup deck scope detection failed', [
                'exception' => $e,
                'organizationId' => $organizationId,
            ]);
            return [
                'boardCount' => 0,
                'warning' => 'Deck export scope detection failed on this instance',
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function collectFilesScopeSummary(int $organizationId): array
    {
        try {
            $projects = $this->findProjectRowsForOrganization($organizationId);
            $folderCount = 0;
            foreach ($projects as $project) {
                $folderId = isset($project['folder_id']) ? (int) $project['folder_id'] : 0;
                if ($folderId > 0) {
                    $folderCount++;
                }
            }

            return [
                'projectCount' => count($projects),
                'sharedFolderCount' => $folderCount,
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('Backup files scope detection failed', [
                'exception' => $e,
                'organizationId' => $organizationId,
            ]);
            return [
                'projectCount' => 0,
                'sharedFolderCount' => 0,
                'warning' => 'File export scope detection failed on this instance',
            ];
        }
    }

    /**
     * @param array<string,mixed> $preSummary
     * @return array<string,mixed>
     */
    private function generateZipToPath(
        int $organizationId,
        int $jobId,
        string $backupType,
        string $tmpZipPath,
        string $artifactName,
        array $preSummary,
    ): array
    {
        $out = fopen($tmpZipPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Failed to open temporary zip for writing');
        }

        $zip = new ZipStreamer([
            'outstream' => $out,
            'zip64' => true,
            'compress' => COMPR::STORE,
        ]);

        $warnings = [];
        $counts = [
            'organization' => 1,
            'members' => 0,
            'subscriptions' => 0,
            'projects' => 0,
            'deckBoards' => 0,
            'files' => 0,
            'deletedFiles' => 0,
        ];
        $fileIndexEntries = [];
        $deletedFiles = [];

        try {
            $dbExport = $this->addDbSection($zip, $organizationId, $counts, $warnings);
            $projectExport = $this->addProjectCreatorSection($zip, $organizationId, $counts, $warnings);
            $deckExport = $this->addDeckSection($zip, $organizationId, $counts, $warnings);
            $filesExport = $this->addFilesSection($zip, $organizationId, $jobId, $backupType, $counts, $warnings, $fileIndexEntries, $deletedFiles);
            $companionFiles = $this->addReadableCompanionFiles(
                $zip,
                $artifactName,
                $backupType,
                $preSummary,
                $counts,
                $warnings,
                $dbExport,
                $projectExport,
                $deckExport,
                $filesExport,
                $deletedFiles,
            );

            $manifest = [
                'formatVersion' => self::BACKUP_FORMAT_VERSION,
                'generatedAt' => $this->utcNowIso8601(),
                'organizationId' => $organizationId,
                'artifactName' => $artifactName,
                'summary' => $preSummary,
                'counts' => $counts,
                'companionFormats' => $companionFiles,
            ];
            if ($warnings !== []) {
                $manifest['warnings'] = $warnings;
            }
            if ($deletedFiles !== []) {
                $manifest['deletedFiles'] = $deletedFiles;
            }

            $this->addJsonFile($zip, 'manifest.json', $manifest);
        } finally {
            $zip->finalize();
            if (is_resource($out)) {
                fclose($out);
            }
        }

        return [
            'counts' => $counts,
            'warnings' => $warnings,
            'deletedFiles' => $deletedFiles,
            'fileIndexEntries' => $fileIndexEntries,
        ];
    }

    /**
     * @param array<string,int> $counts
     * @param list<string> $warnings
     */
    private function addDbSection(ZipStreamer $zip, int $organizationId, array &$counts, array &$warnings): array
    {
        $orgRow = $this->fetchOneById('organizations', $organizationId);
        if ($orgRow === null) {
            throw new \RuntimeException('Organization not found');
        }
        $this->addJsonFile($zip, 'db/organization.json', $orgRow);

        $members = $this->fetchAllWhereInt('organization_members', 'organization_id', $organizationId);
        $counts['members'] = count($members);
        $this->addJsonFile($zip, 'db/organization_members.json', $members);

        $subscriptions = $this->fetchAllWhereInt('subscriptions', 'organization_id', $organizationId);
        $counts['subscriptions'] = count($subscriptions);
        $this->addJsonFile($zip, 'db/subscriptions.json', $subscriptions);

        $subscriptionIds = array_values(array_filter(array_map(static fn (array $row): int => isset($row['id']) ? (int) $row['id'] : 0, $subscriptions), static fn (int $id): bool => $id > 0));
        $subscriptionHistory = $subscriptionIds === [] ? [] : $this->fetchAllWhereInInt('subscriptions_history', 'subscription_id', $subscriptionIds);
        $this->addJsonFile($zip, 'db/subscriptions_history.json', $subscriptionHistory);

        $planIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => isset($row['plan_id']) ? (int) $row['plan_id'] : 0, $subscriptions), static fn (int $id): bool => $id > 0)));
        $plans = $planIds === [] ? [] : $this->fetchAllWhereInInt('plans', 'id', $planIds);
        $this->addJsonFile($zip, 'db/plans.json', $plans);

        return [
            'organization' => $orgRow,
            'members' => $members,
            'subscriptions' => $subscriptions,
            'subscriptionHistory' => $subscriptionHistory,
            'plans' => $plans,
        ];
    }

    /**
     * @param array<string,int> $counts
     * @param list<string> $warnings
     */
    private function addProjectCreatorSection(ZipStreamer $zip, int $organizationId, array &$counts, array &$warnings): array
    {
        try {
            $projects = $this->findProjectRowsForOrganization($organizationId);
        } catch (\Throwable $e) {
            $warnings[] = 'ProjectCreatorAIO tables unavailable; project export skipped';
            $this->logger->debug('ProjectCreator export skipped', ['exception' => $e]);
            return [
                'projects' => [],
                'timeline' => [],
                'notesPublic' => [],
                'notesPrivate' => [],
                'activity' => [],
                'doneSync' => [],
                'fileProcessing' => [],
                'ocrDocTypes' => [],
            ];
        }

        $counts['projects'] = count($projects);
        $this->addJsonFile($zip, 'db/projectcreator/custom_projects.json', $projects);

        $projectIds = array_values(array_filter(array_map(static fn (array $row): int => isset($row['id']) ? (int) $row['id'] : 0, $projects), static fn (int $id): bool => $id > 0));
        if ($projectIds === []) {
            $this->addJsonFile($zip, 'db/projectcreator/project_timeline_items.json', []);
            $this->addJsonFile($zip, 'db/projectcreator/project_notes_public.json', []);
            $this->addJsonFile($zip, 'db/projectcreator/project_notes_private.json', []);
            $this->addJsonFile($zip, 'db/projectcreator/project_activity_events.json', []);
            $this->addJsonFile($zip, 'db/projectcreator/project_deck_done_sync.json', []);
            $this->addJsonFile($zip, 'db/projectcreator/project_file_processing.json', []);
            $this->addJsonFile($zip, 'db/projectcreator/project_ocr_doc_types.json', []);
            return [
                'projects' => $projects,
                'timeline' => [],
                'notesPublic' => [],
                'notesPrivate' => [],
                'activity' => [],
                'doneSync' => [],
                'fileProcessing' => [],
                'ocrDocTypes' => [],
            ];
        }

        $timeline = $this->fetchAllWhereInInt('project_timeline_items', 'project_id', $projectIds);
        $this->addJsonFile($zip, 'db/projectcreator/project_timeline_items.json', $timeline);

        $notesPublic = $this->fetchAllProjectNotesByVisibility($projectIds, 'public');
        $this->addJsonFile($zip, 'db/projectcreator/project_notes_public.json', $notesPublic);
        $notesPrivate = $this->fetchAllProjectNotesByVisibility($projectIds, 'private');
        $this->addJsonFile($zip, 'db/projectcreator/project_notes_private.json', $notesPrivate);

        $activity = $this->fetchAllWhereInInt('project_activity_events', 'project_id', $projectIds);
        $this->addJsonFile($zip, 'db/projectcreator/project_activity_events.json', $activity);

        $doneSync = $this->fetchAllWhereInInt('project_deck_done_sync', 'project_id', $projectIds);
        $this->addJsonFile($zip, 'db/projectcreator/project_deck_done_sync.json', $doneSync);

        $fileProcessing = $this->fetchAllWhereInt('project_file_processing', 'organization_id', $organizationId);
        $this->addJsonFile($zip, 'db/projectcreator/project_file_processing.json', $fileProcessing);

        $ocrDocTypes = $this->fetchAllWhereInt('project_ocr_doc_types', 'organization_id', $organizationId);
        $this->addJsonFile($zip, 'db/projectcreator/project_ocr_doc_types.json', $ocrDocTypes);

        return [
            'projects' => $projects,
            'timeline' => $timeline,
            'notesPublic' => $notesPublic,
            'notesPrivate' => $notesPrivate,
            'activity' => $activity,
            'doneSync' => $doneSync,
            'fileProcessing' => $fileProcessing,
            'ocrDocTypes' => $ocrDocTypes,
        ];
    }

    /**
     * @param array<string,int> $counts
     * @param list<string> $warnings
     */
    private function addDeckSection(ZipStreamer $zip, int $organizationId, array &$counts, array &$warnings): array
    {
        $boardIds = [];
        $boardExports = [];
        try {
            $boardIds = $this->findDeckBoardIdsForOrganization($organizationId);
        } catch (\Throwable $e) {
            $warnings[] = 'Deck tables unavailable; deck export skipped';
            $this->logger->debug('Deck export skipped', ['exception' => $e]);
            return [
                'boardIds' => [],
                'boardExports' => [],
            ];
        }

        $counts['deckBoards'] = count($boardIds);
        foreach ($boardIds as $boardId) {
            try {
                $bundle = $this->exportDeckBoardBundle($boardId);
                $boardExports[] = $bundle;
                $this->addJsonFile($zip, sprintf('deck/boards/%d.json', $boardId), $bundle);
            } catch (\Throwable $e) {
                $warnings[] = sprintf('Failed to export deck board %d', $boardId);
                $this->logger->debug('Deck board export failed', [
                    'exception' => $e,
                    'boardId' => $boardId,
                    'organizationId' => $organizationId,
                ]);
            }
        }

        return [
            'boardIds' => $boardIds,
            'boardExports' => $boardExports,
        ];
    }

    /**
     * @param array<string,int> $counts
     * @param list<string> $warnings
     * @param list<array<string,mixed>> $fileIndexEntries
     * @param list<array<string,mixed>> $deletedFiles
     */
    private function addFilesSection(
        ZipStreamer $zip,
        int $organizationId,
        int $jobId,
        string $backupType,
        array &$counts,
        array &$warnings,
        array &$fileIndexEntries,
        array &$deletedFiles,
    ): array {
        $scanResult = $this->scanOrganizationFiles($organizationId, $warnings);
        if (($scanResult['scanFailed'] ?? false) === true) {
            throw new \RuntimeException('Shared files scan failed');
        }
        $currentEntries = $scanResult['entries'];
        $fileIndexEntries = $scanResult['indexEntries'];
        $fileInventory = $this->buildFileInventoryRows($currentEntries);

        if ($backupType === self::BACKUP_TYPE_FULL) {
            foreach ($currentEntries as $entry) {
                $this->addFileEntryToZip($zip, $entry, $counts);
            }
            return [
                'fileInventory' => $fileInventory,
            ];
        }

        $previousIndex = $this->getFileIndexSnapshotByOrganization($organizationId);
        $currentByFileId = [];
        foreach ($currentEntries as $entry) {
            $fileId = (int) ($entry['fileId'] ?? 0);
            if ($fileId > 0) {
                $currentByFileId[$fileId] = $entry;
            }
        }

        foreach ($currentEntries as $entry) {
            $fileId = (int) ($entry['fileId'] ?? 0);
            if ($fileId <= 0) {
                $this->addFileEntryToZip($zip, $entry, $counts);
                continue;
            }

            $existing = $previousIndex[$fileId] ?? null;
            if ($existing === null || $this->didFileEntryChange($entry, $existing)) {
                $this->addFileEntryToZip($zip, $entry, $counts);
            }
        }

        foreach ($previousIndex as $fileId => $existing) {
            if (isset($currentByFileId[$fileId])) {
                continue;
            }

            $deletedFiles[] = [
                'fileId' => $fileId,
                'path' => (string) ($existing['path'] ?? ''),
                'size' => isset($existing['size']) ? (int) $existing['size'] : null,
                'mtime' => isset($existing['mtime']) ? (int) $existing['mtime'] : null,
                'etag' => isset($existing['etag']) ? (string) $existing['etag'] : null,
                'jobId' => $jobId,
            ];
        }
        $counts['deletedFiles'] = count($deletedFiles);

        $this->addJsonFile($zip, 'changes/deleted_files.json', [
            'jobId' => $jobId,
            'organizationId' => $organizationId,
            'deletedFiles' => $deletedFiles,
        ]);

        return [
            'fileInventory' => $fileInventory,
        ];
    }

    private function sanitizeZipPathSegment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'shared';
        }
        $value = str_replace(['\\', '/', "\0"], '_', $value);
        return $value;
    }

    private function resolveFolderById(int $folderId): ?\OCP\Files\Folder
    {
        $nodes = $this->rootFolder->getById($folderId);
        foreach ($nodes as $node) {
            if ($node instanceof \OCP\Files\Folder) {
                return $node;
            }
        }
        return null;
    }

    /**
     * ProjectCreatorAIO stores the shared project folder in two different ways:
     * - `folder_id`: a filecache/root id for the shared group folder
     * - `folder_path`: the mount name as resolved from a user's files view
     *
     * The `folder_id` lookup is not reliable on every instance for group folders,
     * so we fall back to resolving the shared folder through the project owner.
     *
     * @param array<string,mixed> $project
     */
    private function resolveProjectSharedFolder(array $project): ?\OCP\Files\Folder
    {
        $folderId = isset($project['folder_id']) ? (int) $project['folder_id'] : 0;
        if ($folderId > 0) {
            $folder = $this->resolveFolderById($folderId);
            if ($folder !== null) {
                return $folder;
            }
        }

        $ownerId = trim((string) ($project['owner_id'] ?? ''));
        $folderPath = trim((string) ($project['folder_path'] ?? ''));
        if ($ownerId === '' || $folderPath === '') {
            return null;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($ownerId);
            $node = $userFolder->get($folderPath);
            if ($node instanceof \OCP\Files\Folder) {
                return $node;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * @param list<string> $warnings
     * @return array{entries:list<array<string,mixed>>,indexEntries:list<array<string,mixed>>,scanFailed:bool}
     */
    private function scanOrganizationFiles(int $organizationId, array &$warnings): array
    {
        $projects = [];
        try {
            $projects = $this->findProjectRowsForOrganization($organizationId);
        } catch (\Throwable $e) {
            $warnings[] = 'ProjectCreatorAIO tables unavailable; shared file export skipped';
            $this->logger->debug('Shared file export skipped', ['exception' => $e]);
            return ['entries' => [], 'indexEntries' => [], 'scanFailed' => true];
        }

        $entries = [];
        $indexEntries = [];
        foreach ($projects as $project) {
            $projectId = isset($project['id']) ? (int) $project['id'] : 0;
            $folderId = isset($project['folder_id']) ? (int) $project['folder_id'] : 0;
            $projectName = isset($project['name']) ? (string) $project['name'] : '';
            if ($projectId <= 0) {
                continue;
            }

            try {
                $folder = $this->resolveProjectSharedFolder($project);
                if ($folder === null) {
                    $warnings[] = sprintf('Project %d shared folder not found (folder_id=%d)', $projectId, $folderId);
                    continue;
                }

                $base = sprintf('files/projects/%d/%s/', $projectId, $this->sanitizeZipPathSegment($projectName !== '' ? $projectName : 'shared'));
                $this->collectFolderFileEntries($folder, $base, $projectId, $entries, $indexEntries);
            } catch (\Throwable $e) {
                $warnings[] = sprintf('Failed to scan shared files for project %d', $projectId);
                $this->logger->debug('Shared file scan failed', [
                    'exception' => $e,
                    'organizationId' => $organizationId,
                    'projectId' => $projectId,
                    'folderId' => $folderId,
                ]);
            }
        }

        return [
            'entries' => $entries,
            'indexEntries' => $indexEntries,
            'scanFailed' => false,
        ];
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param list<array<string,mixed>> $indexEntries
     */
    private function collectFolderFileEntries(
        \OCP\Files\Folder $folder,
        string $zipBasePath,
        int $projectId,
        array &$entries,
        array &$indexEntries,
    ): void {
        foreach ($folder->getDirectoryListing() as $node) {
            $name = $this->sanitizeZipPathSegment($node->getName());
            $internal = $zipBasePath . $name;

            if ($node instanceof \OCP\Files\Folder) {
                $this->collectFolderFileEntries($node, $internal . '/', $projectId, $entries, $indexEntries);
                continue;
            }

            if (!$node instanceof \OCP\Files\File) {
                continue;
            }

            $fileId = (int) $node->getId();
            $mtime = (int) $node->getMTime();
            $size = (int) $node->getSize();
            $etag = (string) $node->getEtag();

            $entries[] = [
                'fileId' => $fileId,
                'projectId' => $projectId,
                'path' => $internal,
                'mtime' => $mtime,
                'size' => $size,
                'etag' => $etag,
                'node' => $node,
            ];

            if ($fileId > 0) {
                $indexEntries[] = [
                    'fileId' => $fileId,
                    'projectId' => $projectId,
                    'path' => $internal,
                    'mtime' => $mtime,
                    'size' => $size,
                    'etag' => $etag,
                ];
            }
        }
    }

    private function didFileEntryChange(array $current, array $previous): bool
    {
        if (((string) ($current['etag'] ?? '')) !== ((string) ($previous['etag'] ?? ''))) {
            return true;
        }
        if (((int) ($current['mtime'] ?? 0)) !== ((int) ($previous['mtime'] ?? 0))) {
            return true;
        }
        if (((int) ($current['size'] ?? 0)) !== ((int) ($previous['size'] ?? 0))) {
            return true;
        }
        return ((string) ($current['path'] ?? '')) !== ((string) ($previous['path'] ?? ''));
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,int> $counts
     */
    private function addFileEntryToZip(ZipStreamer $zip, array $entry, array &$counts): void
    {
        $node = $entry['node'] ?? null;
        if (!$node instanceof \OCP\Files\File) {
            return;
        }

        $stream = $node->fopen('rb');
        if ($stream === false) {
            return;
        }

        try {
            $zip->addFileFromStream($stream, (string) $entry['path'], [
                'timestamp' => (int) ($entry['mtime'] ?? 0),
            ]);
            $counts['files']++;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param mixed $data
     */
    private function addJsonFile(ZipStreamer $zip, string $zipPath, $data): void
    {
        $tmpPath = $this->createTempFilePath('.json');
        $fh = fopen($tmpPath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Failed to open temporary json file');
        }

        try {
            fwrite($fh, $this->encodeJsonForExport($data));
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
        }

        $rfh = fopen($tmpPath, 'rb');
        if ($rfh === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('Failed to open temporary json for reading');
        }

        try {
            $zip->addFileFromStream($rfh, $zipPath, [
                'timestamp' => time(),
            ]);
        } finally {
            if (is_resource($rfh)) {
                fclose($rfh);
            }
            @unlink($tmpPath);
        }
    }

    /**
     * @param array<string,mixed> $preSummary
     * @param array<string,int> $counts
     * @param list<string> $warnings
     * @param array<string,mixed> $dbExport
     * @param array<string,mixed> $projectExport
     * @param array<string,mixed> $deckExport
     * @param array<string,mixed> $filesExport
     * @param list<array<string,mixed>> $deletedFiles
     * @return array<string,mixed>
     */
    private function addReadableCompanionFiles(
        ZipStreamer $zip,
        string $artifactName,
        string $backupType,
        array $preSummary,
        array $counts,
        array $warnings,
        array $dbExport,
        array $projectExport,
        array $deckExport,
        array $filesExport,
        array $deletedFiles,
    ): array {
        $deckCsvPayload = $this->buildDeckCompanionCsvPayload($deckExport);
        $csvFiles = [
            'db/organization.csv',
            'db/organization_members.csv',
            'db/subscriptions.csv',
            'db/subscriptions_history.csv',
            'db/plans.csv',
            'db/projectcreator/custom_projects.csv',
            'db/projectcreator/project_notes_public.csv',
            'db/projectcreator/project_notes_private.csv',
            'changes/deleted_files.csv',
            'files/file_inventory.csv',
            ...array_keys($deckCsvPayload),
        ];
        $markdownFiles = [
            'README.md',
            'summary/overview.md',
        ];

        $this->addMarkdownFile($zip, 'README.md', $this->buildArchiveReadmeMarkdown($artifactName, $backupType));
        $this->addMarkdownFile(
            $zip,
            'summary/overview.md',
            $this->buildOverviewMarkdown($artifactName, $backupType, $preSummary, $counts, $warnings, $dbExport, $projectExport, $deckExport, $filesExport, $deletedFiles),
        );

        $organizationRow = is_array($dbExport['organization'] ?? null) ? [$dbExport['organization']] : [];
        $this->addCsvFile($zip, 'db/organization.csv', $organizationRow, $this->buildCsvHeaders($organizationRow));
        $this->addCsvFile($zip, 'db/organization_members.csv', is_array($dbExport['members'] ?? null) ? $dbExport['members'] : [], $this->buildCsvHeaders(is_array($dbExport['members'] ?? null) ? $dbExport['members'] : []));
        $this->addCsvFile($zip, 'db/subscriptions.csv', is_array($dbExport['subscriptions'] ?? null) ? $dbExport['subscriptions'] : [], $this->buildCsvHeaders(is_array($dbExport['subscriptions'] ?? null) ? $dbExport['subscriptions'] : []));
        $this->addCsvFile($zip, 'db/subscriptions_history.csv', is_array($dbExport['subscriptionHistory'] ?? null) ? $dbExport['subscriptionHistory'] : [], $this->buildCsvHeaders(is_array($dbExport['subscriptionHistory'] ?? null) ? $dbExport['subscriptionHistory'] : []));
        $this->addCsvFile($zip, 'db/plans.csv', is_array($dbExport['plans'] ?? null) ? $dbExport['plans'] : [], $this->buildCsvHeaders(is_array($dbExport['plans'] ?? null) ? $dbExport['plans'] : []));
        $this->addCsvFile($zip, 'db/projectcreator/custom_projects.csv', is_array($projectExport['projects'] ?? null) ? $projectExport['projects'] : [], $this->buildCsvHeaders(is_array($projectExport['projects'] ?? null) ? $projectExport['projects'] : []));
        $this->addCsvFile($zip, 'db/projectcreator/project_notes_public.csv', is_array($projectExport['notesPublic'] ?? null) ? $projectExport['notesPublic'] : [], $this->buildCsvHeaders(is_array($projectExport['notesPublic'] ?? null) ? $projectExport['notesPublic'] : []));
        $this->addCsvFile($zip, 'db/projectcreator/project_notes_private.csv', is_array($projectExport['notesPrivate'] ?? null) ? $projectExport['notesPrivate'] : [], $this->buildCsvHeaders(is_array($projectExport['notesPrivate'] ?? null) ? $projectExport['notesPrivate'] : []));
        $this->addCsvFile($zip, 'changes/deleted_files.csv', $deletedFiles, ['fileId', 'path', 'size', 'mtime', 'etag', 'jobId']);
        $this->addCsvFile(
            $zip,
            'files/file_inventory.csv',
            is_array($filesExport['fileInventory'] ?? null) ? $filesExport['fileInventory'] : [],
            ['fileId', 'projectId', 'path', 'size', 'mtime', 'etag'],
        );
        foreach ($deckCsvPayload as $zipPath => $rows) {
            $this->addCsvFile($zip, $zipPath, $rows, $this->buildCsvHeaders($rows));
        }

        return [
            'json' => [
                'canonical' => true,
                'style' => 'pretty',
            ],
            'markdown' => $markdownFiles,
            'csv' => $csvFiles,
        ];
    }

    /**
     * @param mixed $data
     */
    private function encodeJsonForExport($data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    private function addMarkdownFile(ZipStreamer $zip, string $zipPath, string $content): void
    {
        $this->addTextFile($zip, $zipPath, $content);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $headers
     */
    private function addCsvFile(ZipStreamer $zip, string $zipPath, array $rows, array $headers): void
    {
        $this->addTextFile($zip, $zipPath, $this->buildCsvString($rows, $headers));
    }

    /**
     * @param array<string,mixed> $deckExport
     * @return array<string,list<array<string,mixed>>>
     */
    private function buildDeckCompanionCsvPayload(array $deckExport): array
    {
        $payload = [];
        foreach (self::DECK_COMPANION_CSV_FILES as $csvFile) {
            $payload[$csvFile] = [];
        }

        $boardExports = is_array($deckExport['boardExports'] ?? null) ? $deckExport['boardExports'] : [];
        foreach ($boardExports as $boardExport) {
            if (!is_array($boardExport)) {
                continue;
            }

            $board = $boardExport['board'] ?? null;
            if (is_array($board)) {
                $payload['deck/boards.csv'][] = $board;
            }

            $this->appendDeckRows($payload['deck/stacks.csv'], $boardExport['stacks'] ?? null);
            $this->appendDeckRows($payload['deck/cards.csv'], $boardExport['cards'] ?? null);
            $this->appendDeckRows($payload['deck/labels.csv'], $boardExport['labels'] ?? null);
            $this->appendDeckRows($payload['deck/board_acl.csv'], $boardExport['boardAcl'] ?? null);
            $this->appendDeckRows($payload['deck/assigned_users.csv'], $boardExport['assignedUsers'] ?? null);
            $this->appendDeckRows($payload['deck/assigned_labels.csv'], $boardExport['assignedLabels'] ?? null);
            $this->appendDeckRows($payload['deck/attachments.csv'], $boardExport['attachments'] ?? null);
        }

        return $payload;
    }

    /**
     * @param list<array<string,mixed>> $targetRows
     */
    private function appendDeckRows(array &$targetRows, mixed $rows): void
    {
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (is_array($row)) {
                $targetRows[] = $row;
            }
        }
    }

    private function addTextFile(ZipStreamer $zip, string $zipPath, string $content): void
    {
        $tmpPath = $this->createTempFilePath('.txt');
        $fh = fopen($tmpPath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Failed to open temporary text file');
        }

        try {
            fwrite($fh, $content);
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
        }

        $rfh = fopen($tmpPath, 'rb');
        if ($rfh === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('Failed to open temporary text for reading');
        }

        try {
            $zip->addFileFromStream($rfh, $zipPath, [
                'timestamp' => time(),
            ]);
        } finally {
            if (is_resource($rfh)) {
                fclose($rfh);
            }
            @unlink($tmpPath);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function buildCsvHeaders(array $rows): array
    {
        $headers = [];
        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if (is_string($key) && !in_array($key, $headers, true)) {
                    $headers[] = $key;
                }
            }
        }

        return $headers;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $headers
     */
    private function buildCsvString(array $rows, array $headers): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open temporary CSV stream');
        }

        try {
            if ($headers !== []) {
                fputcsv($stream, $headers);
            }

            foreach ($rows as $row) {
                $line = [];
                foreach ($headers as $header) {
                    $line[] = $this->stringifyCsvValue($row[$header] ?? null);
                }
                fputcsv($stream, $line);
            }

            rewind($stream);
            $content = stream_get_contents($stream);
            if ($content === false) {
                throw new \RuntimeException('Failed to read generated CSV');
            }

            return $content;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param mixed $value
     */
    private function stringifyCsvValue($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function buildArchiveReadmeMarkdown(string $artifactName, string $backupType): string
    {
        return implode("\n", [
            '# Organization Backup Archive',
            '',
            sprintf('- Artifact: `%s`', $artifactName),
            sprintf('- Backup type: `%s`', $backupType),
            '- Canonical structured export: pretty-printed JSON files',
            '- Human-readable companions: `summary/overview.md` and CSV files for flat DB and Deck datasets',
            '- Binary shared project files remain under `files/projects/...`',
            '',
            '## Archive layout',
            '',
            '- `manifest.json`: machine-readable archive metadata',
            '- `summary/overview.md`: manager-friendly snapshot of the backup',
            '- `db/**/*.json`: canonical structured exports',
            '- `db/**/*.csv`: spreadsheet-friendly exports for flat tables',
            '- `deck/*.csv`: spreadsheet-friendly Deck table exports',
            '- `deck/boards/*.json`: nested deck board bundles',
            '- `files/file_inventory.csv`: readable inventory of scanned shared files',
            '- `changes/deleted_files.*`: incremental deletion reporting',
            '',
        ]);
    }

    /**
     * @param array<string,mixed> $preSummary
     * @param array<string,int> $counts
     * @param list<string> $warnings
     * @param array<string,mixed> $dbExport
     * @param array<string,mixed> $projectExport
     * @param array<string,mixed> $deckExport
     * @param array<string,mixed> $filesExport
     * @param list<array<string,mixed>> $deletedFiles
     */
    private function buildOverviewMarkdown(
        string $artifactName,
        string $backupType,
        array $preSummary,
        array $counts,
        array $warnings,
        array $dbExport,
        array $projectExport,
        array $deckExport,
        array $filesExport,
        array $deletedFiles,
    ): string {
        $organization = is_array($dbExport['organization'] ?? null) ? $dbExport['organization'] : [];
        $subscriptions = is_array($dbExport['subscriptions'] ?? null) ? $dbExport['subscriptions'] : [];
        $plans = is_array($dbExport['plans'] ?? null) ? $dbExport['plans'] : [];
        $projects = is_array($projectExport['projects'] ?? null) ? $projectExport['projects'] : [];
        $fileInventory = is_array($filesExport['fileInventory'] ?? null) ? $filesExport['fileInventory'] : [];
        $deckBoards = is_array($deckExport['boardIds'] ?? null) ? $deckExport['boardIds'] : [];

        $subscription = isset($subscriptions[0]) && is_array($subscriptions[0]) ? $subscriptions[0] : [];
        $plan = isset($plans[0]) && is_array($plans[0]) ? $plans[0] : [];
        $organizationName = (string) ($organization['name'] ?? ('Organization #' . (string) ($preSummary['organizationId'] ?? '')));

        $lines = [
            '# Backup Overview',
            '',
            sprintf('- Artifact: `%s`', $artifactName),
            sprintf('- Organization: `%s`', $organizationName),
            sprintf('- Backup type: `%s`', $backupType),
            sprintf('- Generated at: `%s`', (string) ($preSummary['generatedAt'] ?? '')),
            sprintf('- Requested by: `%s`', (string) ($preSummary['requestedByUid'] ?? '')),
            '',
            '## Counts',
            '',
            sprintf('- Members: %d', (int) ($counts['members'] ?? 0)),
            sprintf('- Subscriptions: %d', (int) ($counts['subscriptions'] ?? 0)),
            sprintf('- Projects: %d', (int) ($counts['projects'] ?? 0)),
            sprintf('- Deck boards: %d', (int) ($counts['deckBoards'] ?? 0)),
            sprintf('- Files in archive scope: %d', count($fileInventory)),
            sprintf('- Deleted files tracked: %d', count($deletedFiles)),
            '',
            '## Subscription',
            '',
            sprintf('- Status: `%s`', (string) ($subscription['status'] ?? 'n/a')),
            sprintf('- Plan: `%s`', (string) ($plan['name'] ?? ($subscription['plan_id'] ?? 'n/a'))),
            sprintf('- Ends at: `%s`', (string) ($subscription['ended_at'] ?? 'n/a')),
            '',
            '## Projects',
            '',
        ];

        if ($projects === []) {
            $lines[] = '- No projects exported.';
        } else {
            foreach (array_slice($projects, 0, 10) as $project) {
                if (!is_array($project)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- `%s` (id: %s, owner: %s, board: %s)',
                    (string) ($project['name'] ?? 'Unnamed project'),
                    (string) ($project['id'] ?? 'n/a'),
                    (string) ($project['owner_id'] ?? 'n/a'),
                    (string) ($project['board_id'] ?? 'n/a'),
                );
            }
            if (count($projects) > 10) {
                $lines[] = sprintf('- ...and %d more projects in `db/projectcreator/custom_projects.csv`.', count($projects) - 10);
            }
        }
        $lines[] = '- Public notes: `db/projectcreator/project_notes_public.csv`';
        $lines[] = '- Private notes: `db/projectcreator/project_notes_private.csv`';

        $lines[] = '';
        $lines[] = '## Deck';
        $lines[] = '';
        if ($deckBoards === []) {
            $lines[] = '- No deck boards exported.';
        } else {
            $lines[] = sprintf('- Exported board IDs: %s', implode(', ', array_map(static fn ($id): string => (string) $id, array_slice($deckBoards, 0, 10))));
            if (count($deckBoards) > 10) {
                $lines[] = sprintf('- ...and %d more boards in `deck/boards/`.', count($deckBoards) - 10);
            }
        }
        $lines[] = '- Readable Deck table exports are available in `deck/*.csv`.';

        $lines[] = '';
        $lines[] = '## Files';
        $lines[] = '';
        if ($fileInventory === []) {
            $lines[] = '- No shared files exported.';
        } else {
            foreach (array_slice($fileInventory, 0, 10) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- `%s` (%s bytes, project %s)',
                    (string) ($entry['path'] ?? 'unknown'),
                    (string) ($entry['size'] ?? '0'),
                    (string) ($entry['projectId'] ?? 'n/a'),
                );
            }
            if (count($fileInventory) > 10) {
                $lines[] = sprintf('- ...and %d more files in `files/file_inventory.csv`.', count($fileInventory) - 10);
            }
        }

        $lines[] = '';
        $lines[] = '## Warnings';
        $lines[] = '';
        if ($warnings === []) {
            $lines[] = '- None.';
        } else {
            foreach ($warnings as $warning) {
                $lines[] = sprintf('- %s', $warning);
            }
        }

        $lines[] = '';
        $lines[] = '## Deleted Files';
        $lines[] = '';
        if ($deletedFiles === []) {
            $lines[] = '- None.';
        } else {
            foreach (array_slice($deletedFiles, 0, 10) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $lines[] = sprintf('- `%s` (fileId: %s)', (string) ($entry['path'] ?? 'unknown'), (string) ($entry['fileId'] ?? 'n/a'));
            }
            if (count($deletedFiles) > 10) {
                $lines[] = sprintf('- ...and %d more deleted files in `changes/deleted_files.csv`.', count($deletedFiles) - 10);
            }
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return list<array<string,mixed>>
     */
    private function buildFileInventoryRows(array $entries): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                'fileId' => (int) ($entry['fileId'] ?? 0),
                'projectId' => (int) ($entry['projectId'] ?? 0),
                'path' => (string) ($entry['path'] ?? ''),
                'size' => (int) ($entry['size'] ?? 0),
                'mtime' => (int) ($entry['mtime'] ?? 0),
                'etag' => (string) ($entry['etag'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getFileIndexSnapshotByOrganization(int $organizationId): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::FILE_INDEX_TABLE)
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->executeQuery();

        $index = [];
        while (($row = $result->fetch()) !== false) {
            $fileId = isset($row['file_id']) ? (int) $row['file_id'] : 0;
            if ($fileId <= 0) {
                continue;
            }
            $index[$fileId] = [
                'path' => (string) ($row['path'] ?? ''),
                'etag' => (string) ($row['etag'] ?? ''),
                'mtime' => isset($row['mtime']) ? (int) $row['mtime'] : 0,
                'size' => isset($row['size']) ? (int) $row['size'] : 0,
            ];
        }
        $result->closeCursor();

        return $index;
    }

    /**
     * @param list<array<string,mixed>> $entries
     */
    private function replaceFileIndexSnapshot(int $organizationId, int $jobId, array $entries): void
    {
        $delete = $this->db->getQueryBuilder();
        $delete->delete(self::FILE_INDEX_TABLE)
            ->where($delete->expr()->eq('organization_id', $delete->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        $now = $this->utcNow();
        foreach ($entries as $entry) {
            $fileId = isset($entry['fileId']) ? (int) $entry['fileId'] : 0;
            if ($fileId <= 0) {
                continue;
            }

            $insert = $this->db->getQueryBuilder();
            $insert->insert(self::FILE_INDEX_TABLE)
                ->values([
                    'organization_id' => $insert->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT),
                    'file_id' => $insert->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                    'project_id' => $insert->createNamedParameter((int) ($entry['projectId'] ?? 0), IQueryBuilder::PARAM_INT),
                    'path' => $insert->createNamedParameter((string) ($entry['path'] ?? ''), IQueryBuilder::PARAM_STR),
                    'etag' => $insert->createNamedParameter((string) ($entry['etag'] ?? ''), IQueryBuilder::PARAM_STR),
                    'mtime' => $insert->createNamedParameter((int) ($entry['mtime'] ?? 0), IQueryBuilder::PARAM_INT),
                    'size' => $insert->createNamedParameter((int) ($entry['size'] ?? 0), IQueryBuilder::PARAM_INT),
                    'last_backup_job_id' => $insert->createNamedParameter($jobId, IQueryBuilder::PARAM_INT),
                    'updated_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_STR),
                ])
                ->executeStatement();
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchOneById(string $table, int $id): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from($table)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchAllWhereInt(string $table, string $column, int $value): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from($table)
            ->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)))
            ->executeQuery();

        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private function fetchAllWhereInInt(string $table, string $column, array $ids): array
    {
        $rows = [];
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('*')
                ->from($table)
                ->where($qb->expr()->in($column, $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeQuery();

            $part = $result->fetchAll();
            $result->closeCursor();
            $rows = array_merge($rows, $part);
        }

        return $rows;
    }

    /**
     * @param list<int> $projectIds
     * @return list<array<string,mixed>>
     */
    private function fetchAllProjectNotesByVisibility(array $projectIds, string $visibility): array
    {
        $rows = [];
        $normalizedVisibility = strtolower(trim($visibility));
        if (!in_array($normalizedVisibility, ['public', 'private'], true)) {
            return [];
        }

        foreach (array_chunk($projectIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('*')
                ->from('project_notes')
                ->where($qb->expr()->in('project_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->eq('visibility', $qb->createNamedParameter($normalizedVisibility)))
                ->executeQuery();
            $rows = array_merge($rows, $result->fetchAll());
            $result->closeCursor();
        }
        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function findProjectRowsForOrganization(int $organizationId): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from('custom_projects')
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')
            ->executeQuery();

        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function findDeckBoardIdsForOrganization(int $organizationId): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->selectDistinct('board_id')
            ->from('custom_projects')
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNotNull('board_id'))
            ->executeQuery();

        $ids = [];
        while (($row = $result->fetch()) !== false) {
            $raw = $row['board_id'] ?? null;
            if ($raw === null) {
                continue;
            }
            $id = (int) $raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $result->closeCursor();

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @return array<string,mixed>
     */
    private function exportDeckBoardBundle(int $boardId): array
    {
        $board = $this->fetchOneWhereInt('deck_boards', 'id', $boardId);
        if ($board === null) {
            throw new \RuntimeException('Deck board not found');
        }

        $stacks = $this->fetchAllWhereInt('deck_stacks', 'board_id', $boardId);
        $stackIds = array_values(array_filter(array_map(static fn (array $row): int => isset($row['id']) ? (int) $row['id'] : 0, $stacks), static fn (int $id): bool => $id > 0));

        $cards = $stackIds === [] ? [] : $this->fetchAllWhereInInt('deck_cards', 'stack_id', $stackIds);
        $cardIds = array_values(array_filter(array_map(static fn (array $row): int => isset($row['id']) ? (int) $row['id'] : 0, $cards), static fn (int $id): bool => $id > 0));

        $labels = $this->fetchAllWhereInt('deck_labels', 'board_id', $boardId);
        $boardAcl = $this->fetchAllWhereInt('deck_board_acl', 'board_id', $boardId);

        $assignedUsers = $cardIds === [] ? [] : $this->fetchAllWhereInInt('deck_assigned_users', 'card_id', $cardIds);
        $assignedLabels = $cardIds === [] ? [] : $this->fetchAllWhereInInt('deck_assigned_labels', 'card_id', $cardIds);
        $attachments = $cardIds === [] ? [] : $this->fetchAllWhereInInt('deck_attachment', 'card_id', $cardIds);

        return [
            'boardId' => $boardId,
            'board' => $board,
            'stacks' => $stacks,
            'cards' => $cards,
            'labels' => $labels,
            'boardAcl' => $boardAcl,
            'assignedUsers' => $assignedUsers,
            'assignedLabels' => $assignedLabels,
            'attachments' => $attachments,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchOneWhereInt(string $table, string $column, int $value): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from($table)
            ->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    private function storeArtifact(int $organizationId, string $artifactName, string $localPath): void
    {
        $appData = $this->appDataFactory->get('organization');
        $folder = $this->getFolderPath($appData, [
            self::BACKUP_FOLDER,
            'org-' . $organizationId,
        ], true);

        if ($folder->fileExists($artifactName)) {
            $folder->getFile($artifactName)->delete();
        }

        $file = $folder->newFile($artifactName);

        $content = fopen($localPath, 'rb');
        if ($content === false) {
            throw new \RuntimeException('Failed to read generated zip');
        }

        try {
            $file->putContent($content);
        } finally {
            if (is_resource($content)) {
                fclose($content);
            }
        }
    }

    private function deleteArtifactIfExists(int $organizationId, string $artifactName): void
    {
        $appData = $this->appDataFactory->get('organization');
        try {
            $folder = $this->getFolderPath($appData, [
                self::BACKUP_FOLDER,
                'org-' . $organizationId,
            ], false);
        } catch (\Throwable) {
            return;
        }

        if (!$folder->fileExists($artifactName)) {
            return;
        }

        try {
            $folder->getFile($artifactName)->delete();
        } catch (\Throwable) {
        }
    }

    /**
     * @param list<string> $pathParts
     */
    private function getFolderPath(\OCP\Files\IAppData $appData, array $pathParts, bool $create): \OCP\Files\SimpleFS\ISimpleFolder
    {
        $folder = $appData;
        foreach ($pathParts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $folder = $this->getOrCreateFolder($folder, $part, $create);
        }
        return $folder;
    }

    private function getOrCreateFolder(\OCP\Files\IAppData|\OCP\Files\SimpleFS\ISimpleFolder $parent, string $name, bool $create): \OCP\Files\SimpleFS\ISimpleFolder
    {
        try {
            return $parent->getFolder($name);
        } catch (NotFoundException $e) {
            if (!$create) {
                throw $e;
            }

            return $parent->newFolder($name);
        }
    }
}
