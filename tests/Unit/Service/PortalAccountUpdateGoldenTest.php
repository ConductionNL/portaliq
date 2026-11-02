<?php

/**
 * PortalAccountUpdateGoldenTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Every account update the service makes, pinned per case: what it returns
 * and which writes it sends, for an account that is missing, has no
 * identifier, fails to write, or writes.
 *
 * @covers \OCA\Portaliq\Service\PortalAccountService
 * @uses   \OCA\Portaliq\Service\Identity\PortalAccountLookup
 * @uses   \OCA\Portaliq\Service\Identity\WaitingAccountJoin
 */
class PortalAccountUpdateGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/portal-account-updates.json';

	/**
	 * The writes the writer received in the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $writes = [];

	public function testEveryAccountUpdateMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->accounts() as $accountName => $account) {
			foreach ([null, 'written'] as $answer) {
				foreach ($this->calls() as $callName => $call) {
					$service = $this->service(account: $account, answer: $answer);
					$result  = $call($service);
					$actual[$accountName . ' | ' . ($answer ?? 'write fails') . ' | ' . $callName] = [
						'result' => $result,
						'writes' => $this->writes,
					];
				}
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}

	/**
	 * The stored account rows, by case name.
	 *
	 * @return array<string, array<string, mixed>|null>
	 */
	private function accounts(): array {
		$base = ['subjectRef' => 'sub-1', 'identityType' => 'digid', 'identityRef' => 'ref-1', 'organisation' => 'zuid'];

		return [
			'missing' => null,
			'active with uuid' => $base + ['uuid' => 'u-1', 'status' => 'active', 'claims' => ['app' => ['old' => 'x']]],
			'active without identifier' => $base + ['status' => 'active'],
			'pending clerk with self id' => $base + ['@self' => ['id' => 7], 'status' => 'pending', 'provisionedBy' => 'clerk'],
			'pending self-registered' => $base + ['id' => 'u-2', 'status' => 'pending', 'provisionedBy' => 'self-registration'],
			'pending without identifier' => $base + ['status' => 'pending', 'provisionedBy' => 'self-registration'],
			'pending without provisionedBy' => $base + ['uuid' => 'u-3', 'status' => 'pending'],
			'no status' => $base + ['uuid' => 'u-4'],
		];
	}

	/**
	 * The calls that update an account, by name.
	 *
	 * @return array<string, callable(PortalAccountService): mixed>
	 */
	private function calls(): array {
		return [
			'claim' => static fn (PortalAccountService $s): mixed => $s->claim('sub-1', 'app', 'name', 'value'),
			'claim new app' => static fn (PortalAccountService $s): mixed => $s->claim('sub-1', 'other', 'name', 'value'),
			'claim empty value' => static fn (PortalAccountService $s): mixed => $s->claim('sub-1', 'app', 'name', ''),
			'voidPending' => static fn (PortalAccountService $s): mixed => $s->voidPending('sub-1', 'duplicate', 'clerk-2'),
			'voidPending no reason' => static fn (PortalAccountService $s): mixed => $s->voidPending('sub-1', ''),
			'approvePending' => static fn (PortalAccountService $s): mixed => $s->approvePending('sub-1'),
			'findOrCreate' => static fn (PortalAccountService $s): mixed => $s->findOrCreate('digid', 'ref-1', 'zuid', 'client'),
			'findOrCreate other type' => static fn (PortalAccountService $s): mixed => $s->findOrCreate('eidas', 'ref-1', 'zuid', 'client'),
		];
	}

	/**
	 * A service over one stored account and a writer that answers `$answer`.
	 *
	 * @param array<string, mixed>|null $account The stored account.
	 * @param string|null               $answer  Null when every write fails.
	 *
	 * @return PortalAccountService
	 */
	private function service(?array $account, ?string $answer): PortalAccountService {
		$this->writes = [];
		$rows = ($account === null) ? [] : [$account];

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200, string $scopeClaim = '', string $contributingApp = '', mixed $via = null, string $audience = '', mixed $fields = null, array $filter = []) use ($rows): array {
				return array_values(array_filter($rows, static fn (array $row): bool => ($row[$scopeField] ?? null) === $subjectRef));
			}
		);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array|\Closure $data) use ($answer): ?array {
				$data = $this->withoutClock(data: $data);
				$this->writes[] = compact('register', 'schema', 'scopeField', 'subjectRef', 'organisation', 'id', 'data');

				return ($answer === null) ? null : ['uuid' => $id];
			}
		);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): ?array {
				$this->writes[] = ['create' => $schema, 'data' => $this->withoutClock(data: $data)];

				return null;
			}
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('minted');

		return new PortalAccountService($reader, $writer, $random);
	}

	/**
	 * A write's data with its login moment replaced by a marker.
	 *
	 * @param array<string, mixed> $data The written data.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutClock(array $data): array {
		foreach (['lastLoginAt', 'createdAt', 'provisionedAt'] as $key) {
			if (isset($data[$key]) === true) {
				$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)$data[$key]);
				$data[$key] = '(now)';
			}
		}

		return $data;
	}
}
