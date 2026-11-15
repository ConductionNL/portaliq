<?php

/**
 * Golden outcome of an emergency push whose deliveries partly fail: every
 * guardian is tried in order, a throwing delivery is logged and counted as
 * undelivered, and the push is reported as not reaching everyone.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\EmergencyPushController;
use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use OCA\Portaliq\Service\Notifications\PushDeliveryService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A mixed delivery run, recorded call by call.
 */
class EmergencyPushDeliveryGoldenTest extends TestCase {
	use StaffActionDoubleTrait;

	/**
	 * Delivered, thrown, refused and delivered again: two of four, every call
	 * and every log line recorded.
	 *
	 * @return void
	 */
	public function testAMixedRunIsRecordedCallByCall(): void {
		$seen = ['target' => null, 'deliveries' => [], 'logs' => []];
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('staff-1');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$audience = $this->createMock(GuardianAudienceFixtureReader::class);
		$audience->method('guardiansMatching')->willReturnCallback(static function (array $target) use (&$seen): array {
			$seen['target'] = $target;

			return ['g-1', 'g-2', 'g-3', 'g-4'];
		});
		$delivery = $this->createMock(PushDeliveryService::class);
		$delivery->method('deliver')->willReturnCallback(static function (string $subjectRef, string $title, string $body, bool $emergency) use (&$seen): bool {
			$seen['deliveries'][] = [$subjectRef, $title, $body, $emergency];
			if ($subjectRef === 'g-2') {
				throw new RuntimeException('transport down');
			}

			return $subjectRef !== 'g-3';
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(static function (string $message, array $context = []) use (&$seen, $level): void {
				$seen['logs'][] = [$level, $message, $context];
			});
		}

		$controller = new EmergencyPushController($this->createMock(IRequest::class), $userSession, $audience, $delivery, $logger, $this->staffActionAuth(EmergencyPushController::ACTION));
		$response   = $controller->send(['groupRefs' => ['5a']], 'Alarm', 'Evacuate');

		$this->assertSame([200, ['recipientCount' => 4, 'deliveredCount' => 2]], [$response->getStatus(), $response->getData()]);
		$this->assertSame(
			[
				'target'     => ['groupRefs' => ['5a']],
				'deliveries' => [
					['g-1', 'Alarm', 'Evacuate', true],
					['g-2', 'Alarm', 'Evacuate', true],
					['g-3', 'Alarm', 'Evacuate', true],
					['g-4', 'Alarm', 'Evacuate', true],
				],
				'logs'       => [
					['warning', 'Portaliq: emergency push delivery failed', ['reason' => 'transport down']],
					['warning', 'Portaliq: emergency push not delivered to every recipient', ['sentBy' => 'staff-1', 'recipientCount' => 4, 'deliveredCount' => 2]],
				],
			],
			$seen
		);
	}//end testAMixedRunIsRecordedCallByCall()
}//end class
