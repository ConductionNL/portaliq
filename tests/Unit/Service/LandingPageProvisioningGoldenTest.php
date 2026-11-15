<?php

/**
 * LandingPageProvisioningGoldenTest
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

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Event\LandingPageRequestedEvent;
use OCA\Portaliq\Service\LandingPageProvisioningService;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every read, write and event outcome of a landing-page request, per refusal and per failed write.
 *
 * @covers \OCA\Portaliq\Service\LandingPageProvisioningService
 * @uses   \OCA\Portaliq\Event\LandingPageRequestedEvent
 * @uses   \OCA\Portaliq\Service\RowIdLookup
 */
class LandingPageProvisioningGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/landing-page-provisioning.json';

	/**
	 * Every scenario's trace matches the recorded one.
	 *
	 * @return void
	 */
	public function testEveryScenarioMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->trace(scenario: $scenario);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: $actual[$name], message: $name);
		}
	}//end testEveryScenarioMatchesTheGolden()

	/**
	 * Provision one request over recording doubles.
	 *
	 * @param array<string, mixed> $scenario The request and the store's answers.
	 *
	 * @return array<string, mixed>
	 */
	private function trace(array $scenario): array {
		$log = [];

		$reader = $this->createMock(originalClassName: ScopedCollectionReaderInterface::class);
		$reader->method('readCollection')->willReturnCallback(
			static function (
				string $register,
				string $schema,
				string $scopeField,
				string $subjectRef,
				string $organisation = '',
				int $limit = 200,
				string $scopeClaim = '',
				string $contributingApp = '',
				mixed $via = null,
				string $audience = '',
				mixed $fields = null,
				array $filter = []
			) use (
				&$log,
				$scenario
			): array {
				$log[] = ['read', $register, $schema, $scopeField, $subjectRef, $limit, $filter];
				return ($scenario['rows'][$schema] ?? []);
			}
		);

		$writer = $this->createMock(originalClassName: PortalObjectWriter::class);
		$writer->method('createAnonymousObject')->willReturnCallback(
			static function (string $register, string $schema, array $data) use (&$log, $scenario): ?array {
				$log[] = ['write', $register, $schema, $data];
				if (array_key_exists($schema, ($scenario['written'] ?? [])) === true) {
					return $scenario['written'][$schema];
				}

				return ['@self' => ['id' => $schema . '-1']];
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context = []) use (&$log): void {
			$log[] = ['log.warning', $message, $context];
		});

		$request = array_replace($this->request(), ($scenario['request'] ?? []));
		$event   = new LandingPageRequestedEvent(...$request);
		(new LandingPageProvisioningService(reader: $reader, writer: $writer, logger: $logger))->provision(event: $event);

		return [
			'log'     => $log,
			'pageId'  => $event->getPageId(),
			'formId'  => $event->getFormId(),
			'url'     => $event->getPublicUrl(),
			'error'   => $event->getError(),
			'handled' => $event->isHandled(),
		];
	}//end trace()

	/**
	 * A valid request.
	 *
	 * @return array<string, mixed>
	 */
	private function request(): array {
		return [
			'sourceApp'         => 'pipelinq',
			'portal'            => 'zuid',
			'route'             => '/Actie',
			'title'             => 'Actie',
			'locale'            => 'nl',
			'article'           => ['summary' => 'Kort', 'body' => 'Lang verhaal', 'heroImageRef' => 'img-1', 'links' => [['href' => '/a']]],
			'form'              => [
				'fields'      => [['id' => 'naam', 'label' => 'Naam', 'type' => 'text']],
				'submitLabel' => 'Verstuur',
				'consentText' => 'Akkoord',
			],
			'utm'               => ['source' => 'mail'],
			'externalReference' => 'ext-1',
			'correlationId'     => 'c-1',
		];
	}//end request()

	/**
	 * The scenarios by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$portal   = ['slug' => 'zuid', 'domains' => [['hostname' => 'a.example', 'verified' => false], ['hostname' => 'b.example', 'verified' => true]]];
		$known    = ['rows' => ['portal' => [$portal]]];
		$field    = ['id' => 'naam', 'label' => 'Naam', 'type' => 'text'];
		$base     = $this->request();

		return [
			'empty portal'               => ['request' => ['portal' => '']] + $known,
			'unknown portal'             => [],
			'route taken any case'       => ['rows' => ['portal' => [$portal], 'page' => [['portal' => 'zuid', 'route' => '/actie']]]],
			'route taken in other portal' => [
				'rows' => ['portal' => [$portal], 'page' => [['portal' => 'noord', 'route' => '/Actie'], ['route' => '/Actie']]],
			],
			'article without summary'    => ['request' => ['article' => ['summary' => '  ', 'body' => 'x']]] + $known,
			'article without body'       => ['request' => ['article' => ['summary' => 'x']]] + $known,
			'form without fields'        => ['request' => ['form' => ['submitLabel' => 'Go']]] + $known,
			'form field not an array'    => ['request' => ['form' => ['fields' => ['naam'], 'submitLabel' => 'Go']]] + $known,
			'form field without type'    => [
				'request' => ['form' => ['fields' => [['id' => 'a', 'label' => 'A', 'type' => ' ']], 'submitLabel' => 'Go']],
			] + $known,
			'form without submit label'  => ['request' => ['form' => ['fields' => [$field], 'submitLabel' => ' ']]] + $known,
			'form write fails'           => ['written' => ['form' => null]] + $known,
			'form write without id'      => ['written' => ['form' => ['@self' => ['id' => '']]]] + $known,
			'form id flat int'           => ['written' => ['form' => ['id' => 42]]] + $known,
			'form uuid only'             => ['written' => ['form' => ['uuid' => 'u-9', '@self' => 'x']]] + $known,
			'page write fails'           => ['written' => ['page' => null]] + $known,
			'page uuid in self'          => ['written' => ['page' => ['@self' => ['uuid' => 'pu-1']]]] + $known,
			'everything lands'           => $known,
			'destination carried'        => [
				'request' => [
					'form' => $base['form'] + [
						'destination' => ['register' => ' dossiq ', 'schema' => 'lead', 'fields' => ['naam', 3, 'mail'], 'fixed' => ['bron' => 'web']],
					],
				],
			] + $known,
			'destination without schema' => ['request' => ['form' => $base['form'] + ['destination' => ['register' => 'dossiq']]]] + $known,
			'first domain unverified'    => ['rows' => ['portal' => [['slug' => 'zuid', 'domains' => [['hostname' => 'a.example'], 'odd']]]]],
			'first domain not an array'  => ['rows' => ['portal' => [['slug' => 'zuid', 'domains' => ['odd', ['hostname' => 'a.example']]]]]],
			'no domains'                 => ['rows' => ['portal' => [['slug' => 'zuid']]]],
			'verified without hostname'  => [
				'rows' => ['portal' => [['slug' => 'zuid', 'domains' => [['verified' => true], ['hostname' => 'b.example', 'verified' => true]]]]],
			],
			'minimal article and form'   => [
				'request' => [
					'article'           => ['summary' => 's', 'body' => 'b'],
					'form'              => ['fields' => [$field], 'submitLabel' => 'Go'],
					'utm'               => [],
					'externalReference' => '',
				],
			] + $known,
		];
	}//end scenarios()
}//end class
