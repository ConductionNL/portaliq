<?php

/**
 * Unit tests for the census of apps that contribute to the portal.
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
 *
 * @spec openspec/changes/contributing-apps-census/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Service\ContributionCensus;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A contributing, a missing, a throwing and a provider-less app, each on its own row.
 *
 * @covers \OCA\Portaliq\Service\ContributionCensus
 * @uses   \OCA\Portaliq\Contribution\ProviderAudiences
 *
 * @spec openspec/changes/contributing-apps-census/specs/portal-contribution-contract/spec.md#requirement-an-administrator-can-see-which-apps-contribute-to-the-portal-req-cac-001
 */
class ContributionCensusTest extends TestCase {

	/**
	 * The subjects each provider was asked for.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $asked = [];

	/**
	 * A provider serving one audience and declaring the given contribution.
	 *
	 * @param string $audience     The audience.
	 * @param mixed  $contribution What getContribution() answers.
	 *
	 * @return object The provider.
	 */
	private static function singleAudience(string $audience, mixed $contribution): object {
		return new class ($audience, $contribution) {
			/**
			 * Constructor.
			 *
			 * @param string $audience     The audience.
			 * @param mixed  $contribution The contribution.
			 */
			public function __construct(public string $audience, public mixed $contribution) {
			}

			/**
			 * The audience.
			 *
			 * @return string
			 */
			public function getAudience(): string {
				return $this->audience;
			}

			/**
			 * The contribution.
			 *
			 * @param array<string, mixed> $subject The subject.
			 *
			 * @return mixed
			 */
			public function getContribution(array $subject): mixed {
				return $this->contribution;
			}
		};
	}//end singleAudience()

	/**
	 * A census over the given enabled apps and their providers.
	 *
	 * @param array<int, string>   $enabled   The enabled app ids.
	 * @param array<string, object> $providers The provider per app id; absent is no provider.
	 * @param LoggerInterface|null $logger    The logger, or a silent one.
	 *
	 * @return ContributionCensus The census.
	 */
	private function census(array $enabled, array $providers, ?LoggerInterface $logger = null): ContributionCensus {
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getEnabledApps')->willReturn($enabled);
		$apps->expects($this->never())->method('getInstalledApps');

		$locator = $this->createMock(originalClassName: PortalProviderLocator::class);
		$locator->method('locate')->willReturnCallback(static fn (string $appId): ?object => ($providers[$appId] ?? null));
		$locator->method('contributionOf')->willReturnCallback(
			function (object $provider, array $subject) use ($providers): mixed {
				$this->asked[] = [array_search($provider, $providers, true), $subject];
				return $provider->getContribution($subject);
			}
		);

		return new ContributionCensus(
			appManager: $apps,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class)),
			locator: $locator
		);
	}//end census()

	/**
	 * A case app with a provider is listed with its audience and counts; an office app without one contributes nothing.
	 *
	 * @return void
	 */
	public function testAContributingAndAMissingAppAreBothListed(): void {
		$dossiq = self::singleAudience(
			audience: 'client',
			contribution: ['collections' => [['id' => 'cases'], ['id' => 'documents']], 'pages' => [['id' => 'my-cases']], 'actions' => []]
		);

		$rows = $this->census(enabled: ['keepiq', 'dossiq'], providers: ['dossiq' => $dossiq])->rows();

		$this->assertSame(
			expected: [
				[
					'app'       => 'dossiq',
					'status'    => ContributionCensus::STATUS_PROVIDER,
					'pattern'   => '',
					'error'     => '',
					'audiences' => [['audience' => 'client', 'collections' => 2, 'pages' => 1, 'actions' => 0]],
				],
				['app' => 'keepiq', 'status' => ContributionCensus::STATUS_NONE, 'pattern' => '', 'error' => '', 'audiences' => []],
			],
			actual: $rows
		);
	}//end testAContributingAndAMissingAppAreBothListed()

	/**
	 * Each audience is counted for a synthetic subject that names only the audience.
	 *
	 * @return void
	 */
	public function testEveryAudienceIsCountedForASyntheticSubject(): void {
		$multi = new class () {
			/**
			 * The audiences.
			 *
			 * @return array<int, mixed>
			 */
			public function getAudiences(): array {
				return ['citizen', 'business', '', 7];
			}

			/**
			 * A contribution that differs per audience.
			 *
			 * @param array<string, mixed> $subject The subject.
			 *
			 * @return array<string, mixed>
			 */
			public function getContribution(array $subject): array {
				if ($subject['audience'] === 'citizen') {
					return ['collections' => [[], [], []], 'pages' => [[], []], 'actions' => [[]]];
				}

				return ['collections' => 'not a list', 'actions' => [[], []]];
			}
		};

		$rows = $this->census(enabled: ['permits'], providers: ['permits' => $multi])->rows();

		$this->assertSame(
			expected: [
				['audience' => 'citizen', 'collections' => 3, 'pages' => 2, 'actions' => 1],
				['audience' => 'business', 'collections' => 0, 'pages' => 0, 'actions' => 2],
			],
			actual: $rows[0]['audiences']
		);
		$this->assertSame(
			expected: [['permits', ['audience' => 'citizen']], ['permits', ['audience' => 'business']]],
			actual: $this->asked
		);
	}//end testEveryAudienceIsCountedForASyntheticSubject()

	/**
	 * A provider that throws is listed with the error class, is logged, and every other app is still listed.
	 *
	 * @return void
	 */
	public function testABrokenProviderDoesNotHideTheOthers(): void {
		$broken = new class () {
			/**
			 * The audience.
			 *
			 * @return string
			 */
			public function getAudience(): string {
				return 'client';
			}

			/**
			 * Fails.
			 *
			 * @param array<string, mixed> $subject The subject.
			 *
			 * @return array<string, mixed>
			 */
			public function getContribution(array $subject): array {
				throw new RuntimeException('register down');
			}
		};
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Portaliq: contribution census could not read a provider',
			['app' => 'broken', 'exception' => RuntimeException::class]
		);

		$rows = $this->census(
			enabled: ['broken', 'dossiq', 'zeta'],
			providers: ['broken' => $broken, 'dossiq' => self::singleAudience(audience: 'client', contribution: ['pages' => [[]]])],
			logger: $logger
		)->rows();

		$this->assertSame(expected: ['broken', 'dossiq', 'zeta'], actual: array_column($rows, 'app'));
		$this->assertSame(expected: ContributionCensus::STATUS_ERROR, actual: $rows[0]['status']);
		$this->assertSame(expected: RuntimeException::class, actual: $rows[0]['error']);
		$this->assertSame(expected: [], actual: $rows[0]['audiences']);
		$this->assertSame(expected: ContributionCensus::STATUS_PROVIDER, actual: $rows[1]['status']);
		$this->assertSame(expected: ContributionCensus::STATUS_NONE, actual: $rows[2]['status']);
	}//end testABrokenProviderDoesNotHideTheOthers()

	/**
	 * Opencatalogi without a provider is listed with its own pattern; with a provider it is counted like any other.
	 *
	 * @return void
	 */
	public function testOpencatalogiIsNamedAsItsOwnPattern(): void {
		$rows = $this->census(enabled: ['opencatalogi'], providers: [])->rows();
		$this->assertSame(
			expected: [
				[
					'app'       => 'opencatalogi',
					'status'    => ContributionCensus::STATUS_ENDPOINTS,
					'pattern'   => 'public search and publication endpoints',
					'error'     => '',
					'audiences' => [],
				],
			],
			actual: $rows
		);

		$withProvider = $this->census(
			enabled: ['opencatalogi'],
			providers: ['opencatalogi' => self::singleAudience(audience: 'public', contribution: ['collections' => [[]]])]
		)->rows();
		$this->assertSame(expected: ContributionCensus::STATUS_PROVIDER, actual: $withProvider[0]['status']);
		$this->assertSame(expected: '', actual: $withProvider[0]['pattern']);
	}//end testOpencatalogiIsNamedAsItsOwnPattern()

	/**
	 * Apps are listed once each in id order; a provider serving no audience and a non-array contribution count nothing.
	 *
	 * @return void
	 */
	public function testRowsAreOrderedUniqueAndEmptyContributionsCountNothing(): void {
		$noAudience = new class () {
			/**
			 * Never asked.
			 *
			 * @param array<string, mixed> $subject The subject.
			 *
			 * @return array<string, mixed>
			 */
			public function getContribution(array $subject): array {
				throw new RuntimeException('a provider serving nobody is not asked');
			}
		};

		$rows = $this->census(
			enabled: ['b', 'a', 'b'],
			providers: ['a' => $noAudience, 'b' => self::singleAudience(audience: 'client', contribution: null)]
		)->rows();

		$this->assertSame(expected: ['a', 'b'], actual: array_column($rows, 'app'));
		$this->assertSame(expected: ContributionCensus::STATUS_PROVIDER, actual: $rows[0]['status']);
		$this->assertSame(expected: [], actual: $rows[0]['audiences']);
		$this->assertSame(
			expected: [['audience' => 'client', 'collections' => 0, 'pages' => 0, 'actions' => 0]],
			actual: $rows[1]['audiences']
		);
	}//end testRowsAreOrderedUniqueAndEmptyContributionsCountNothing()
}//end class
