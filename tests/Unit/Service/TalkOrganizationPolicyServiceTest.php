<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

use OCA\Organization\Db\UserMapper;
use OCA\Organization\Service\TalkOrganizationPolicyService;

use OCP\IGroupManager;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TalkOrganizationPolicyServiceTest extends TestCase
{
    private UserMapper&MockObject $userMapper;
    private IGroupManager&MockObject $groupManager;
    private TalkOrganizationPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 5) . '/lib/public/IGroupManager.php';

        $this->userMapper = $this->createMock(UserMapper::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        $this->service = new TalkOrganizationPolicyService(
            $this->userMapper,
            $this->groupManager,
        );
    }

    public function testCanUserUseTalkAllowsGlobalAdmin(): void
    {
        $this->groupManager->expects($this->once())
            ->method('isAdmin')
            ->with('admin')
            ->willReturn(true);
        $this->userMapper->expects($this->never())
            ->method('getOrganizationMembership');

        $this->assertTrue($this->service->canUserUseTalk('admin'));
    }

    public function testCanUsersCommunicateRequiresSharedOrganization(): void
    {
        $this->groupManager->expects($this->exactly(4))
            ->method('isAdmin')
            ->willReturn(false);
        $this->userMapper->expects($this->once())
            ->method('getOrganizationMemberships')
            ->with(['alice', 'bob'])
            ->willReturn([
                'alice' => ['organization_id' => 7, 'role' => 'member'],
                'bob' => ['organization_id' => 7, 'role' => 'member'],
            ]);

        $this->assertTrue($this->service->canUsersCommunicate('alice', 'bob'));
    }

    public function testCanUserAccessRoomRejectsCrossOrganizationRoom(): void
    {
        $this->groupManager->expects($this->atLeast(1))
            ->method('isAdmin')
            ->willReturn(false);
        $this->userMapper->expects($this->atLeast(1))
            ->method('getOrganizationMembership')
            ->with('alice')
            ->willReturn(['organization_id' => 7, 'role' => 'member']);
        $this->userMapper->expects($this->atLeast(1))
            ->method('getOrganizationMemberships')
            ->willReturnCallback(static function (array $userIds): array {
                $memberships = [];
                foreach ($userIds as $userId) {
                    $memberships[$userId] = [
                        'organization_id' => $userId === 'bob' ? 8 : 7,
                        'role' => 'member',
                    ];
                }

                return $memberships;
            });

        $this->assertFalse($this->service->canUserAccessRoom('alice', ['alice', 'bob']));
        $this->assertTrue($this->service->canUserAccessRoom('alice', ['alice', 'charlie']));
    }
}
