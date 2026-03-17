<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\ITempManager;

use ZipStreamer\COMPR;
use ZipStreamer\ZipStreamer;

use Psr\Log\LoggerInterface;

class OrganizationBackupService
{
    private const JOBS_TABLE = 'org_backup_jobs';
    private const STEPS_TABLE = 'org_backup_steps';
    private const EVENTS_TABLE = 'org_backup_events';

    private const BACKUP_FOLDER = 'org-backups';

    /** @var list<string> */
    private const STEP_ORDER = ['collect_db', 'export_deck', 'export_files', 'finalize'];

    public function __construct(
        private IDBConnection $db,
        private IAppDataFactory $appDataFactory,
        private IRootFolder $rootFolder,
        private ITempManager $tempManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function createJob(int $organizationId, string $requestedByUid, array $options = []): array
    {
        if ($organizationId <= 0) {
            throw new \InvalidArgumentException('organizationId must be a positive integer');
        }

        $requestedByUid = trim($requestedByUid);
        if ($requestedByUid === '') {
            throw new \InvalidArgumentException('requestedByUid must not be empty');
        }

        $now = $this->utcNow();
        $expiresAt = $this->utcNowPlusSeconds(24 * 60 * 60);

        $insert = $this->db->getQueryBuilder();
        $insert->insert(self::JOBS_TABLE)
            ->values([
                'organization_id' => $insert->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT),
                'requested_by_uid' => $insert->createNamedParameter($requestedByUid, IQueryBuilder::PARAM_STR),
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
        $this->cleanupExpired();

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

            $artifactName = $this->buildArtifactName($organizationId, $jobId);

            $this->markStepRunning($jobId, 'collect_db');
            $summary = [
                'formatVersion' => 1,
                'generatedAt' => $this->utcNowIso8601(),
                'organizationId' => $organizationId,
                'requestedByUid' => $requestedByUid,
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
            $zipSummary = $this->generateZipToPath($organizationId, $tmpPath, $artifactName, $summary);
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
                'summary' => $zipSummary,
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
    }

    public function openArtifactStream(int $organizationId, string $artifactName)
    {
        $appData = $this->appDataFactory->get('organization');
        $folder = $this->getFolderPath($appData, [
            self::BACKUP_FOLDER,
            'org-' . $organizationId,
        ], false);

        $file = $folder->getFile($artifactName);
        return $file->fopen('rb');
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
        $this->insertEvent($jobId, 'info', 'Step started', ['stepKey' => $stepKey], $stepKey);
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
        $this->insertEvent($jobId, 'info', 'Step completed', ['stepKey' => $stepKey], $stepKey);
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
    private function generateZipToPath(int $organizationId, string $tmpZipPath, string $artifactName, array $preSummary): array
    {
        $out = fopen($tmpZipPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Failed to open temporary zip for writing');
        }

        $zip = new ZipStreamer([
            'outstream' => $out,
            'zip64' => true,
            'compress' => COMPR::DEFLATE,
        ]);

        $warnings = [];
        $counts = [
            'organization' => 1,
            'members' => 0,
            'subscriptions' => 0,
            'projects' => 0,
            'deckBoards' => 0,
            'files' => 0,
        ];

        try {
            $this->addDbSection($zip, $organizationId, $counts, $warnings);
            $this->addProjectCreatorSection($zip, $organizationId, $counts, $warnings);
            $this->addDeckSection($zip, $organizationId, $counts, $warnings);
            $this->addFilesSection($zip, $organizationId, $counts, $warnings);

            $manifest = [
                'formatVersion' => 1,
                'generatedAt' => $this->utcNowIso8601(),
                'organizationId' => $organizationId,
                'artifactName' => $artifactName,
                'summary' => $preSummary,
                'counts' => $counts,
            ];
            if ($warnings !== []) {
                $manifest['warnings'] = $warnings;
            }

            $this->addJsonFile($zip, 'manifest.json', $manifest);
        } finally {
            $zip->finalize();
            fclose($out);
        }

        return [
            'counts' => $counts,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string,int> $counts
     * @param list<string> $warnings
     */
    private function addDbSection(ZipStreamer $zip, int $organizationId, array &$counts, array &$warnings): void
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
    }

    /**
     * @param array<string,int> $counts
     * @param list<string> $warnings
     */
    private function addProjectCreatorSection(ZipStreamer $zip, int $organizationId, array &$counts, array &$warnings): void
    {
        try {
            $projects = $this->findProjectRowsForOrganization($organizationId);
        } catch (\Throwable $e) {
            $warnings[] = 'ProjectCreatorAIO tables unavailable; project export skipped';
            $this->logger->debug('ProjectCreator export skipped', ['exception' => $e]);
            return;
        }

        $counts['projects'] = count($projects);
        $this->addJsonFile($zip, 'db/projectcreator/custom_projects.json', $projects);

        $projectIds = array_values(array_filter(array_map(static fn (array $row): int => isset($row['id']) ? (int) $row['id'] : 0, $projects), static fn (int $id): bool => $id > 0));
        if ($projectIds === []) {
            $this->addJsonFile($zip, 'db/projectcreator/project_timeline_items.json', []);
            $this->addJsonFile($zip, 'db/projectcreator/project_notes_public.json', []);
            $this->addJsonFile($zip, 'db/projectcreator/project_activity_events.json', []);
            $this->addJsonFile($zip, 'db/projectcreator/project_deck_done_sync.json', []);
            $this->addJsonFile($zip, 'db/projectcreator/project_file_processing.json', []);
            $this->addJsonFile($zip, 'db/projectcreator/project_ocr_doc_types.json', []);
            return;
        }

        $timeline = $this->fetchAllWhereInInt('project_timeline_items', 'project_id', $projectIds);
        $this->addJsonFile($zip, 'db/projectcreator/project_timeline_items.json', $timeline);

        $notes = $this->fetchAllPublicProjectNotes($projectIds);
        $this->addJsonFile($zip, 'db/projectcreator/project_notes_public.json', $notes);

        $activity = $this->fetchAllWhereInInt('project_activity_events', 'project_id', $projectIds);
        $this->addJsonFile($zip, 'db/projectcreator/project_activity_events.json', $activity);

        $doneSync = $this->fetchAllWhereInInt('project_deck_done_sync', 'project_id', $projectIds);
        $this->addJsonFile($zip, 'db/projectcreator/project_deck_done_sync.json', $doneSync);

        $fileProcessing = $this->fetchAllWhereInt('project_file_processing', 'organization_id', $organizationId);
        $this->addJsonFile($zip, 'db/projectcreator/project_file_processing.json', $fileProcessing);

        $ocrDocTypes = $this->fetchAllWhereInt('project_ocr_doc_types', 'organization_id', $organizationId);
        $this->addJsonFile($zip, 'db/projectcreator/project_ocr_doc_types.json', $ocrDocTypes);
    }

    /**
     * @param array<string,int> $counts
     * @param list<string> $warnings
     */
    private function addDeckSection(ZipStreamer $zip, int $organizationId, array &$counts, array &$warnings): void
    {
        $boardIds = [];
        try {
            $boardIds = $this->findDeckBoardIdsForOrganization($organizationId);
        } catch (\Throwable $e) {
            $warnings[] = 'Deck tables unavailable; deck export skipped';
            $this->logger->debug('Deck export skipped', ['exception' => $e]);
            return;
        }

        $counts['deckBoards'] = count($boardIds);
        foreach ($boardIds as $boardId) {
            try {
                $bundle = $this->exportDeckBoardBundle($boardId);
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
    }

    /**
     * @param array<string,int> $counts
     * @param list<string> $warnings
     */
    private function addFilesSection(ZipStreamer $zip, int $organizationId, array &$counts, array &$warnings): void
    {
        $projects = [];
        try {
            $projects = $this->findProjectRowsForOrganization($organizationId);
        } catch (\Throwable $e) {
            $warnings[] = 'ProjectCreatorAIO tables unavailable; shared file export skipped';
            $this->logger->debug('Shared file export skipped', ['exception' => $e]);
            return;
        }

        foreach ($projects as $project) {
            $projectId = isset($project['id']) ? (int) $project['id'] : 0;
            $folderId = isset($project['folder_id']) ? (int) $project['folder_id'] : 0;
            $projectName = isset($project['name']) ? (string) $project['name'] : '';

            if ($projectId <= 0 || $folderId <= 0) {
                continue;
            }

            try {
                $folder = $this->resolveFolderById($folderId);
                if ($folder === null) {
                    $warnings[] = sprintf('Project %d shared folder not found (folder_id=%d)', $projectId, $folderId);
                    continue;
                }

                $base = sprintf('files/projects/%d/%s/', $projectId, $this->sanitizeZipPathSegment($projectName !== '' ? $projectName : 'shared'));
                $this->addFolderRecursiveToZip($zip, $folder, $base, $counts);
            } catch (\Throwable $e) {
                $warnings[] = sprintf('Failed to export shared files for project %d', $projectId);
                $this->logger->debug('Shared file export failed', [
                    'exception' => $e,
                    'organizationId' => $organizationId,
                    'projectId' => $projectId,
                    'folderId' => $folderId,
                ]);
            }
        }
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
     * @param array<string,int> $counts
     */
    private function addFolderRecursiveToZip(ZipStreamer $zip, \OCP\Files\Folder $folder, string $zipBasePath, array &$counts): void
    {
        $zip->addEmptyDir(rtrim($zipBasePath, '/'));

        foreach ($folder->getDirectoryListing() as $node) {
            $name = $this->sanitizeZipPathSegment($node->getName());
            $internal = $zipBasePath . $name;

            if ($node instanceof \OCP\Files\Folder) {
                $this->addFolderRecursiveToZip($zip, $node, $internal . '/', $counts);
                continue;
            }

            if ($node instanceof \OCP\Files\File) {
                $stream = $node->fopen('rb');
                if ($stream === false) {
                    continue;
                }
                $zip->addFileFromStream($stream, $internal, [
                    'timestamp' => $node->getMTime(),
                ]);
                fclose($stream);
                $counts['files']++;
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
            fwrite($fh, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } finally {
            fclose($fh);
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
            fclose($rfh);
            @unlink($tmpPath);
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
    private function fetchAllPublicProjectNotes(array $projectIds): array
    {
        $rows = [];
        foreach (array_chunk($projectIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('*')
                ->from('project_notes')
                ->where($qb->expr()->in('project_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->eq('visibility', $qb->createNamedParameter('public')))
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
            fclose($content);
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

    private function getOrCreateFolder(\OCP\Files\SimpleFS\ISimpleFolder $parent, string $name, bool $create): \OCP\Files\SimpleFS\ISimpleFolder
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
