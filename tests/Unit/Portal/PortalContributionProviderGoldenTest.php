<?php

/**
 * Golden test for PortalContributionProvider::getContribution.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Portal;

use OCA\Portaliq\Portal\PortalContributionProvider;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The contribution per world (active portal pages, active forms, the subject's
 * audience), with every read, random draw and log line, against a recording
 * made on the code before the split. `submittedAt` is checked to be an ATOM
 * time and then recorded as `<atom>`, because it is the clock.
 *
 * @covers \OCA\Portaliq\Portal\PortalContributionProvider
 */
class PortalContributionProviderGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/portal-contribution-provider.json';

	/**
	 * @return void
	 */
	public function testEveryWorldMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->worlds() as $name => $world) {
			$actual[$name] = $this->trace(world: $world);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: $actual[$name], message: $name);
		}
	}//end testEveryWorldMatchesTheGolden()

	/**
	 * Ask for the contribution in one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return array<string, mixed> `calls` and `contribution`.
	 */
	private function trace(array $world): array {
		$log    = [];
		$reader = $this->createMock(originalClassName: ScopedCollectionReaderInterface::class);
		$reader->method('readCollection')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200, string $scopeClaim = '', string $contributingApp = '', mixed $via = null, string $audience = '', mixed $fields = null, array $filter = []) use (&$log, $world): array {
				$log[] = ['read', $register, $schema, $scopeField, $subjectRef, $organisation, $limit, $scopeClaim, $contributingApp, $via, $audience, $fields, $filter];
				return ($world[$schema] ?? []);
			}
		);
		$draws  = 0;
		$random = $this->createMock(originalClassName: ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static function (int $length, string $characters = '') use (&$log, &$draws): string {
				$draws++;
				$log[] = ['random', $length, $characters];
				return 'nonce-' . $draws;
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$log): void {
				$log[] = ['log.warning', $message, $context];
			}
		);

		$provider     = new PortalContributionProvider($reader, $random, $logger);
		$contribution = $provider->getContribution(subject: $world['subject']);
		foreach (($contribution['actions'] ?? []) as $index => $action) {
			if (is_array($action) === true && isset($action['server']['submittedAt']) === true) {
				$this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $action['server']['submittedAt']));
				$contribution['actions'][$index]['server']['submittedAt'] = '<atom>';
			}
		}

		return ['calls' => $log, 'contribution' => $contribution];
	}//end trace()

	/**
	 * The worlds.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function worlds(): array {
		$page    = [
			'@self'         => ['id' => 'p-2'],
			'audience'      => 'resident',
			'label'         => 'Mijn omgeving',
			'minTrust'      => 'substantial',
			'collections'   => [['id' => 'cases'], ['id' => 'docs', 'minTrust' => 'high'], 'loose'],
			'actions'       => [['id' => 'act']],
			'pages'         => [['route' => '/mijn']],
			'notifications' => ['b' => 'message.created', 'a' => 'status.changed'],
		];
		$form    = [
			'id'                => 'f-1',
			'fields'            => [['id' => 'name'], ['id' => ''], 'loose', ['label' => 'no id'], ['id' => 7], ['id' => 'email']],
			'destination'       => ['register' => ' stackiq ', 'schema' => ' organization ', 'fixed' => ['status' => 'pending']],
			'submitLabel'       => 'Aanmelden',
			'portal'            => 'zuiddrecht',
			'pageRoute'         => 'aanmelden',
			'pageId'            => 'pg-1',
			'sourceApp'         => 'stackiq',
			'externalReference' => 'org-registration',
			'campaign'          => ['utm' => 'spring'],
		];
		$minimal = ['uuid' => 'f-2', 'fields' => [['id' => 'q']], 'destination' => ['register' => 'r', 'schema' => 's']];

		return [
			'no audience'                         => ['subject' => []],
			'empty audience'                      => ['subject' => ['audience' => '']],
			'no page for the audience'            => ['subject' => ['audience' => 'resident'], 'portalPage' => [['audience' => 'company'] + $page]],
			'one page'                            => ['subject' => ['audience' => 'resident'], 'portalPage' => [$page]],
			'two pages, lowest id wins'           => ['subject' => ['audience' => 'resident'], 'portalPage' => [$page, ['@self' => ['id' => 'p-1'], 'label' => 'Eerste'] + $page, ['audience' => 'other'] + $page]],
			'page without trust or notifications' => ['subject' => ['audience' => 'resident'], 'portalPage' => [['audience' => 'resident', 'id' => 9, 'minTrust' => '', 'notifications' => [], 'collections' => 'x']]],
			'page with non-string trust'          => ['subject' => ['audience' => 'resident'], 'portalPage' => [['minTrust' => 3, 'notifications' => 'no'] + $page]],
			'resident never gets forms'           => ['subject' => ['audience' => 'resident'], 'form' => [$form]],
			'anonymous without forms or page'     => ['subject' => ['audience' => 'anonymous']],
			'anonymous with forms only'           => ['subject' => ['audience' => 'anonymous'], 'form' => [$form, $minimal]],
			'anonymous with page and forms'       => ['subject' => ['audience' => 'anonymous'], 'portalPage' => [['audience' => 'anonymous'] + $page], 'form' => [$minimal]],
			'anonymous page, no usable form'      => ['subject' => ['audience' => 'anonymous'], 'portalPage' => [['audience' => 'anonymous'] + $page], 'form' => [['fields' => [['id' => 'x']]] + array_diff_key($minimal, ['uuid' => 1])]],
			'form ids from self'                  => ['subject' => ['audience' => 'anonymous'], 'form' => [['@self' => ['id' => '', 'uuid' => 'self-u']] + array_diff_key($minimal, ['uuid' => 1]), ['@self' => ['id' => 12]] + array_diff_key($minimal, ['uuid' => 1]), ['id' => 0, 'uuid' => 1.5] + array_diff_key($minimal, ['uuid' => 1])]],
			'form without fields skipped'         => ['subject' => ['audience' => 'anonymous'], 'form' => [['fields' => [['id' => '']]] + $minimal, ['fields' => 'x'] + $minimal]],
			'form without destination'            => ['subject' => ['audience' => 'anonymous'], 'form' => [['destination' => ['register' => 'r', 'schema' => '  ']] + $minimal, ['destination' => ['schema' => 's']] + $minimal, ['destination' => 'x'] + $minimal, $form]],
			'form destination not an array'       => ['subject' => ['audience' => 'anonymous'], 'form' => [array_diff_key($minimal, ['destination' => 1])]],
		];
	}//end worlds()
}//end class
