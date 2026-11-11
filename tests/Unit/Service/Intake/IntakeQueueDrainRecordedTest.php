<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\IntakeQueueDrain;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One drain pass over recorded entries and destination answers.
 */
#[CoversClass(IntakeQueueDrain::class)]
class IntakeQueueDrainRecordedTest extends TestCase {
	/**
	 * Every entry is submitted, marked and counted exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryEntryIsAsRecorded(): void {
		$calls   = [];
		$entries = [
			['reference' => 'R-1', 'portal' => 'x', 'route' => 'gone'],
			[
				'reference' => 'R-2', 'portal' => 'x', 'route' => 'form', 'answers' => ['a' => 1], 'subjectRef' => 's-1', 'origin' => 'web',
				'statements' => ['st'], 'decisions' => ['d'], 'verifiedEmails' => ['e@x'], 'computed' => ['total', 5],
			],
			['reference' => 'R-3', 'portal' => 'x', 'route' => 'form', 'answers' => 'not a list'],
			['reference' => 'R-4', 'portal' => 'x', 'route' => 'form'],
			['portal' => 'x', 'route' => 'form'],
			['reference' => 'R-6', 'portal' => 'x', 'route' => 'form'],
		];
		$outcomes = [
			'R-2' => ['ok' => true, 'answer' => ['id' => 'case-2', 'reference' => 'Z-2', 'confirmation' => ['deadline' => '2026-10-01']]],
			'R-3' => ['ok' => true, 'answer' => ['confirmation' => ['deadline' => '1 October']]],
			'R-4' => ['ok' => false, 'message' => 'Refused. ', 'errors' => []],
			''    => ['ok' => false, 'message' => 'Missing', 'errors' => ['field' => 'subject']],
			'R-6' => ['ok' => true, 'answer' => []],
		];

		$queue = $this->createMock(PortalIntakeQueue::class);
		$queue->method('pending')->willReturnCallback(
			static function (int $limit) use (&$calls, $entries): array {
				$calls[] = 'pending ' . $limit;
				return $entries;
			}
		);
		$queue->method('markRegistered')->willReturnCallback(
			static function (array $submission, string $caseId, string $externalReference = '', string $dueAt = '') use (&$calls): bool {
				$calls[] = 'registered ' . ($submission['reference'] ?? '-') . ' ' . $caseId . ' ' . $externalReference . ' ' . $dueAt;
				return true;
			}
		);
		$queue->method('markFailed')->willReturnCallback(
			static function (array $submission, string $reason) use (&$calls): bool {
				$calls[] = 'failed ' . ($submission['reference'] ?? '-') . ' ' . $reason;
				return true;
			}
		);
		$bindings = $this->createMock(PortalFormBindingResolver::class);
		$bindings->method('bindingFor')->willReturnCallback(
			static fn (string $portal, string $route): ?array => ($route === 'form' ? ['portal' => $portal, 'route' => $route] : null)
		);
		$bindings->method('render')->willReturn(['kind' => 'hosted']);
		$intake = $this->createMock(DestinationIntake::class);
		$intake->method('submit')->willReturnCallback(
			static function (array $binding, array $render, array $answers, string $subjectRef = '', array $server = [], string $idempotencyKey = '') use (&$calls, $outcomes): array {
				$calls[] = 'submit ' . json_encode([$binding, $render, $answers, $subjectRef, $server, $idempotencyKey]);
				return $outcomes[$idempotencyKey];
			}
		);

		$result = (new IntakeQueueDrain($queue, $bindings, $intake))->drain(limit: 7);

		$this->assertSame(
			[
				'delivered' => 3,
				'refused' => 3,
				'waiting' => 6,
				'reasons' => [
					'R-1' => 'The form this request came from is no longer published.',
					'R-4' => 'Refused.',
					'' => 'Missing {"field":"subject"}',
				],
			],
			$result
		);
		$this->assertSame(
			[
				'pending 7',
				'failed R-1 The form this request came from is no longer published.',
				'submit [{"portal":"x","route":"form"},{"kind":"hosted"},{"a":1},"s-1",{"externalReference":"R-2","portal":"x","origin":"web","statements":["st"],"decisions":["d"],"verifiedEmails":["e@x"],"fieldMeta":{"total":{"computed":true}}},"R-2"]',
				'registered R-2 case-2 Z-2 2026-10-01T00:00:00+00:00',
				'submit [{"portal":"x","route":"form"},{"kind":"hosted"},["not a list"],"",{"externalReference":"R-3","portal":"x","origin":"","statements":[],"decisions":[],"verifiedEmails":[],"fieldMeta":[]},"R-3"]',
				'registered R-3   ',
				'submit [{"portal":"x","route":"form"},{"kind":"hosted"},[],"",{"externalReference":"R-4","portal":"x","origin":"","statements":[],"decisions":[],"verifiedEmails":[],"fieldMeta":[]},"R-4"]',
				'failed R-4 Refused.',
				'submit [{"portal":"x","route":"form"},{"kind":"hosted"},[],"",{"externalReference":"","portal":"x","origin":"","statements":[],"decisions":[],"verifiedEmails":[],"fieldMeta":[]},""]',
				'failed - Missing {"field":"subject"}',
				'submit [{"portal":"x","route":"form"},{"kind":"hosted"},[],"",{"externalReference":"R-6","portal":"x","origin":"","statements":[],"decisions":[],"verifiedEmails":[],"fieldMeta":[]},"R-6"]',
				'registered R-6   ',
				'pending 7',
			],
			$calls
		);
	}//end testEveryEntryIsAsRecorded()
}//end class
