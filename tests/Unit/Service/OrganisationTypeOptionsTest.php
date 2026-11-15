<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use OCA\Portaliq\Service\OrganisationTypeOptions;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The organisation types come from OpenRegister's concept register (REQ-PIA-003).
 *
 * The double is built from OpenRegister's ConceptRepository (its public
 * signatures on development: scheme(string \$schemeUri): ?array and
 * conceptsOf(string \$schemeUri): array).
 *
 * @covers \OCA\Portaliq\Service\OrganisationTypeOptions
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs
 */
final class OrganisationTypeOptionsTest extends TestCase {

	private const SCHEME = OrganisationTypeOptions::DEFAULT_SCHEME;

	private const WATERSCHAP = 'https://identifier.overheid.nl/tooi/def/ont/Waterschap';

	private const GEMEENTE = 'https://identifier.overheid.nl/tooi/def/ont/Gemeente';

	private const OUD = 'https://identifier.overheid.nl/tooi/def/ont/Deelgemeente';

	/**
	 * The concepts of the scheme as the concept register keys them.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function concepts(): array {
		return [
			self::WATERSCHAP => ['uri' => self::WATERSCHAP, 'prefLabel' => ['nl' => 'waterschap']],
			self::GEMEENTE => ['uri' => self::GEMEENTE, 'prefLabel' => ['nl' => 'gemeente']],
			self::OUD => ['uri' => self::OUD, 'prefLabel' => ['nl' => 'deelgemeente'], 'deprecated' => true],
		];
	}//end concepts()

	/**
	 * The options over a repository double; `$scheme` null means the scheme is not installed.
	 *
	 * @param ConceptRepository&MockObject $repository The concept register double.
	 * @param string                       $configured The scheme uri in the app config.
	 */
	private function options(ConceptRepository $repository, string $configured=''): OrganisationTypeOptions {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\Vocabulary\\ConceptRepository')->willReturn($repository);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('portaliq', OrganisationTypeOptions::SCHEME_KEY, '')->willReturn($configured);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new OrganisationTypeOptions(container: $container, appConfig: $config, l10n: $l10n, logger: new NullLogger());
	}//end options()

	/**
	 * A repository double holding the scheme `$uri` with the concepts above.
	 *
	 * @param string|null $uri The installed scheme's uri, or null for none.
	 */
	private function repository(?string $uri): ConceptRepository {
		$repository = $this->createMock(ConceptRepository::class);
		$repository->method('scheme')->willReturnCallback(
			static fn (string $schemeUri): ?array => ($schemeUri === $uri ? ['uri' => $uri, 'title' => 'Organisatietypen'] : null)
		);
		$repository->method('conceptsOf')->willReturnCallback(
			static fn (string $schemeUri): array => ($schemeUri === $uri ? self::concepts() : [])
		);

		return $repository;
	}//end repository()

	public function testTheOptionsComeFromTheConceptRegister(): void {
		$options = $this->options(repository: $this->repository(uri: self::SCHEME))->options();

		self::assertTrue($options['installed']);
		self::assertNull($options['message']);
		// Sorted by label, the deprecated concept not offered.
		self::assertSame(
			[['value' => self::GEMEENTE, 'label' => 'gemeente'], ['value' => self::WATERSCHAP, 'label' => 'waterschap']],
			$options['options']
		);
	}//end testTheOptionsComeFromTheConceptRegister()

	public function testAMissingSchemeOffersNothing(): void {
		$repository = $this->repository(uri: null);
		$repository->expects(self::never())->method('conceptsOf');
		$options = $this->options(repository: $repository)->options();

		self::assertFalse($options['installed']);
		self::assertSame([], $options['options']);
		self::assertStringContainsString('not installed', (string)$options['message']);
	}//end testAMissingSchemeOffersNothing()

	public function testAnInstanceMayNameItsOwnScheme(): void {
		$own = 'https://example.nl/schemes/organisatietypen';
		$options = $this->options(repository: $this->repository(uri: $own), configured: '  ' . $own . ' ')->options();

		self::assertTrue($options['installed']);
		self::assertCount(2, $options['options']);
	}//end testAnInstanceMayNameItsOwnScheme()

	public function testAnUnreadableRegisterOffersNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$options = new OrganisationTypeOptions(
			container: $container,
			appConfig: $this->createMock(IAppConfig::class),
			l10n: $l10n,
			logger: new NullLogger()
		);

		self::assertSame(['installed' => false, 'options' => []], array_intersect_key($options->options(), ['installed' => 1, 'options' => 1]));
	}//end testAnUnreadableRegisterOffersNothing()

	public function testAPickedTypeStoresItsLabel(): void {
		$result = $this->options(repository: $this->repository(uri: self::SCHEME))->settle(
			portal: ['slug' => 'hhnk', 'organisationType' => self::WATERSCHAP, 'organisationTypeLabel' => 'gemeente'],
			old: []
		);

		self::assertNull($result['refusal']);
		self::assertSame('waterschap', $result['portal']['organisationTypeLabel']);
		self::assertSame(self::WATERSCHAP, $result['portal']['organisationType']);
	}//end testAPickedTypeStoresItsLabel()

	public function testFreeTextIsRefused(): void {
		$result = $this->options(repository: $this->repository(uri: self::SCHEME))->settle(
			portal: ['organisationType' => 'Waterschap'],
			old: ['organisationType' => self::GEMEENTE]
		);

		self::assertSame('Choose the organisation type from the TOOI list.', $result['refusal']);
	}//end testFreeTextIsRefused()

	public function testWithoutTheListTheStoredTypeAndLabelAreKept(): void {
		$old    = ['organisationType' => self::WATERSCHAP, 'organisationTypeLabel' => 'waterschap'];
		$types  = $this->options(repository: $this->repository(uri: null));
		$kept   = $types->settle(portal: ['organisationType' => self::WATERSCHAP, 'title' => 'Nieuw'], old: $old);
		$change = $types->settle(portal: ['organisationType' => self::GEMEENTE], old: $old);

		self::assertNull($kept['refusal']);
		self::assertSame('waterschap', $kept['portal']['organisationTypeLabel']);
		self::assertSame('Nieuw', $kept['portal']['title']);
		self::assertNotNull($change['refusal']);
	}//end testWithoutTheListTheStoredTypeAndLabelAreKept()

	public function testAnEmptyTypeClearsTheLabel(): void {
		$result = $this->options(repository: $this->repository(uri: self::SCHEME))->settle(
			portal: ['organisationType' => '  ', 'organisationTypeLabel' => 'waterschap'],
			old: []
		);

		self::assertNull($result['refusal']);
		self::assertSame([], $result['portal']);
	}//end testAnEmptyTypeClearsTheLabel()
}//end class
