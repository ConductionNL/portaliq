<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\BackgroundJob\NotificationDispatchJob;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the manifest `notifications` rule-key matcher: a declared key on the
 * matched app's aggregated contribution enqueues the async dispatch job with a
 * content-free payload; a missing/unknown/malformed declaration enqueues
 * nothing (fail-closed); and dispatch() itself never throws, mirroring
 * SubmissionReceiptService's fail-safe posture.
 *
 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
 * @spec openspec/specs/supplier-portal/spec.md#dispatch-is-decoupled-from-the-request-path
 */
class NotificationDispatchServiceTest extends TestCase {
	private const SUBJECT = [
		'subjectRef' => 's1',
		'organisation' => 'org-1',
		'audience' => 'supplier',
	];

	private function aggregateWith(mixed $notifications): array {
		return [
			'audience' => 'supplier',
			'organisation' => 'org-1',
			'contributions' => [
				[
					'app' => 'portaliq',
					'notifications' => $notifications,
				],
			],
		];
	}//end aggregateWith()

	public function testAMatchingRuleKeyEnqueuesTheDispatchJobWithAContentFreePayload(): void {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn($this->aggregateWith(['message.created', 'status.changed']));

		$captured = [];
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('add')->willReturnCallback(
			function (string $job, $argument) use (&$captured) {
				$captured = ['job' => $job, 'argument' => $argument];
			}
		);

		$service = new NotificationDispatchService($registry, $jobList, $this->createMock(LoggerInterface::class));
		$service->dispatch(NotificationDispatchService::RULE_MESSAGE_CREATED, 'portaliq', self::SUBJECT);

		$this->assertSame(NotificationDispatchJob::class, $captured['job']);
		$this->assertSame('s1', $captured['argument']['subjectRef']);
		$this->assertSame('org-1', $captured['argument']['organisation']);
		$this->assertSame('supplier', $captured['argument']['audience']);
		$this->assertSame('portaliq', $captured['argument']['appId']);
		$this->assertSame('message.created', $captured['argument']['ruleKey']);
		// Content-free: no message/case data in the payload.
		$this->assertArrayNotHasKey('data', $captured['argument']);
		$this->assertArrayNotHasKey('whitelistedData', $captured['argument']);

	}//end testAMatchingRuleKeyEnqueuesTheDispatchJobWithAContentFreePayload()

	public function testNoMatchingRuleKeyEnqueuesNothing(): void {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn($this->aggregateWith(['status.changed']));

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('add');

		$service = new NotificationDispatchService($registry, $jobList, $this->createMock(LoggerInterface::class));
		$service->dispatch(NotificationDispatchService::RULE_MESSAGE_CREATED, 'portaliq', self::SUBJECT);

	}//end testNoMatchingRuleKeyEnqueuesNothing()

	public function testMissingNotificationsFieldEnqueuesNothing(): void {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(
			[
				'audience' => 'supplier',
				'organisation' => 'org-1',
				'contributions' => [['app' => 'portaliq']],
			]
		);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('add');

		$service = new NotificationDispatchService($registry, $jobList, $this->createMock(LoggerInterface::class));
		$service->dispatch(NotificationDispatchService::RULE_MESSAGE_CREATED, 'portaliq', self::SUBJECT);

	}//end testMissingNotificationsFieldEnqueuesNothing()

	/**
	 * A malformed `notifications` declaration (not an array of strings) fails
	 * CLOSED — never treated as "everything matches".
	 */
	public function testMalformedNotificationsDeclarationFailsClosed(): void {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn($this->aggregateWith('not-an-array'));

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('add');

		$service = new NotificationDispatchService($registry, $jobList, $this->createMock(LoggerInterface::class));
		$service->dispatch(NotificationDispatchService::RULE_MESSAGE_CREATED, 'portaliq', self::SUBJECT);

	}//end testMalformedNotificationsDeclarationFailsClosed()

	public function testUnmatchedAppIdEnqueuesNothing(): void {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn($this->aggregateWith(['message.created']));

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('add');

		$service = new NotificationDispatchService($registry, $jobList, $this->createMock(LoggerInterface::class));
		$service->dispatch(NotificationDispatchService::RULE_MESSAGE_CREATED, 'other-app', self::SUBJECT);

	}//end testUnmatchedAppIdEnqueuesNothing()

	public function testEmptyRuleKeyOrAppIdOrSubjectRefEnqueuesNothingWithoutCallingTheRegistry(): void {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->expects($this->never())->method('aggregateFor');

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('add');

		$service = new NotificationDispatchService($registry, $jobList, $this->createMock(LoggerInterface::class));
		$service->dispatch('', 'portaliq', self::SUBJECT);
		$service->dispatch(NotificationDispatchService::RULE_MESSAGE_CREATED, '', self::SUBJECT);
		$service->dispatch(NotificationDispatchService::RULE_MESSAGE_CREATED, 'portaliq', ['organisation' => 'org-1']);

	}//end testEmptyRuleKeyOrAppIdOrSubjectRefEnqueuesNothingWithoutCallingTheRegistry()

	/**
	 * dispatch() never throws — a notification side-effect can never turn an
	 * already-successful domain action into a failed one, mirroring
	 * SubmissionReceiptService's fail-safe posture.
	 */
	public function testARegistryFailureIsSwallowedAndNeverThrows(): void {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willThrowException(new RuntimeException('OR is down'));

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('add');

		$service = new NotificationDispatchService($registry, $jobList, $this->createMock(LoggerInterface::class));
		$service->dispatch(NotificationDispatchService::RULE_MESSAGE_CREATED, 'portaliq', self::SUBJECT);
		$this->addToAssertionCount(1);

	}//end testARegistryFailureIsSwallowedAndNeverThrows()
}//end class
