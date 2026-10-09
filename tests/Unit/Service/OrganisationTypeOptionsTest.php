<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use OCA\Portaliq\Service\OrganisationTypeOptions;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The organisation type picker reads the TOOI scheme from OpenRegister's
 * concept register (portal-identity-from-the-admin REQ-PIA-003).
 *
 * The repository double is built from OpenRegister's real class; the label
 * and the lifecycle are OpenRegister's real ConceptHierarchy and
 * ConceptLifecycle.
 *
 * @covers \OCA\Portaliq\Service\OrganisationTypeOptions
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */
class OrganisationTypeOptionsTest extends TestCase {

	private const SCHEME = 'https://identifier.overheid.nl/tooi/set/organisatietypen';

	protected function setUp(): void {
		if (class_exists(ConceptRepository::class) === false) {
			$this->markTestSkipped('OpenRegister classes are not loadable (set PORTALIQ_OPENREGISTER_LIB).');
		}
	}//end setUp()

	/**
	 * @return void
	 */
	public function testTheOptionsComeFromTheConceptRegister(): void {
		$options = $this->options(
			scheme: self::SCHEME,
			concepts: [
				'https://example.nl/waterschap' => ['uri' => 'https://example.nl/waterschap', 'prefLabel' => ['nl' => 'waterschap']],
				'https://example.nl/gemeente'   => ['uri' => 'https://example.nl/gemeente', 'prefLabel' => ['nl' => 'gemeente', 'en' => 'municipality']],
				'https://example.nl/oud'        => ['uri' => 'https://example.nl/oud', 'prefLabel' => ['nl' => 'deelgemeente'], 'deprecated' => true],
			]
		)->options();

		$this->assertTrue($options['installed']);
		$this->assertSame(
			[
				['uri' => 'https://example.nl/gemeente', 'label' => 'gemeente'],
				['uri' => 'https://example.nl/waterschap', 'label' => 'waterschap'],
			],
			$options['options']
		);
	}//end testTheOptionsComeFromTheConceptRegister()

	/**
	 * @return void
	 */
	public function testAMissingSchemeOffersNothing(): void {
		$missing = $this->options(scheme: self::SCHEME, concepts: [], schemeExists: false)->options();
		$this->assertSame(['installed' => false, 'options' => []], $missing);

		$unset = $this->options(scheme: '', concepts: [])->options();
		$this->assertSame(['installed' => false, 'options' => []], $unset);
	}//end testAMissingSchemeOffersNothing()

	/**
	 * Without OpenRegister's vocabulary classes the picker offers nothing.
	 *
	 * @return void
	 */
	public function testWithoutTheConceptRegisterNothingIsOffered(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(self::SCHEME);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not installed'));

		$this->assertSame(['installed' => false, 'options' => []], (new OrganisationTypeOptions($container, $config, new NullLogger()))->options());
	}//end testWithoutTheConceptRegisterNothingIsOffered()

	/**
	 * The service over a repository holding one scheme.
	 *
	 * @param string                              $scheme       The configured scheme uri.
	 * @param array<string, array<string, mixed>> $concepts     The scheme's concepts by uri.
	 * @param bool                                $schemeExists Whether the scheme object is in the register.
	 *
	 * @return OrganisationTypeOptions
	 */
	private function options(string $scheme, array $concepts, bool $schemeExists=true): OrganisationTypeOptions {
		$repository = $this->getMockBuilder(ConceptRepository::class)->disableOriginalConstructor()->onlyMethods(['scheme', 'conceptsOf'])->getMock();
		$repository->method('scheme')->willReturnCallback(
			static fn (string $schemeUri) => ($schemeExists === true && $schemeUri === self::SCHEME) ? ['uri' => self::SCHEME, '@uuid' => 's1'] : null
		);
		$repository->method('conceptsOf')->willReturnCallback(
			static fn (string $schemeUri) => ($schemeUri === self::SCHEME) ? $concepts : []
		);

		$hierarchy = new ConceptHierarchy(new ConceptLifecycle());
		$lifecycle = new ConceptLifecycle();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				ConceptRepository::class => $repository,
				ConceptHierarchy::class => $hierarchy,
				ConceptLifecycle::class => $lifecycle,
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('portaliq', 'organisation_type_scheme', '')->willReturn($scheme);

		return new OrganisationTypeOptions($container, $config, new NullLogger());
	}//end options()
}//end class
