<?php

/**
 * PortalAdminGrants: a portal's `adminGroups` enter the rules of the content
 * it owns as conditional rules for that portal only, and every other rule is
 * left as it was (operate-portals-per-organisation REQ-OPO-005).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Tenancy
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
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Tenancy;

use OCA\Portaliq\Service\Tenancy\PortalAdminGrants;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(PortalAdminGrants::class)]
#[UsesClass(PortalAdminScope::class)]
class PortalAdminGrantsTest extends TestCase {

	/**
	 * The page schema's rules on an instance with one editor group.
	 *
	 * @var array<string, mixed>
	 */
	private const PAGE_RULES = [
		'read'   => [['group' => 'public', 'match' => ['status' => 'published']], 'portaliq-medewerkers'],
		'create' => ['redactie'],
		'update' => ['redactie'],
		'delete' => ['redactie'],
		'export' => ['portaliq-medewerkers'],
	];

	public function testThePortalsGroupsAreGrantedItsOwnRowsOnly(): void {
		$rules = PortalAdminGrants::withPortal(self::PAGE_RULES, 'portal', 'zeist', ['zeist-beheer']);

		$grant = ['group' => 'zeist-beheer', 'match' => ['portal' => 'zeist']];
		$this->assertSame(['redactie', $grant], $rules['create']);
		$this->assertSame(['redactie', $grant], $rules['update']);
		$this->assertSame(['redactie', $grant], $rules['delete']);
		$this->assertSame([self::PAGE_RULES['read'][0], 'portaliq-medewerkers', $grant], $rules['read']);
		$this->assertSame(['portaliq-medewerkers'], $rules['export'], 'An action outside ACTIONS is untouched.');
	}//end testThePortalsGroupsAreGrantedItsOwnRowsOnly()

	public function testWritingAgainReplacesThatPortalsRulesAndKeepsAnotherPortals(): void {
		$rules = PortalAdminGrants::withPortal(self::PAGE_RULES, 'portal', 'zeist', ['zeist-beheer']);
		$rules = PortalAdminGrants::withPortal($rules, 'portal', 'utrecht', ['utrecht-beheer']);
		$rules = PortalAdminGrants::withPortal($rules, 'portal', 'zeist', ['zeist-redactie', '', 'zeist-redactie']);

		$this->assertSame(
			['redactie', ['group' => 'utrecht-beheer', 'match' => ['portal' => 'utrecht']], ['group' => 'zeist-redactie', 'match' => ['portal' => 'zeist']]],
			$rules['update']
		);
	}//end testWritingAgainReplacesThatPortalsRulesAndKeepsAnotherPortals()

	public function testARenamedOrDeletedPortalLosesItsRules(): void {
		$rules = PortalAdminGrants::withPortal(self::PAGE_RULES, 'portal', 'zeist', ['zeist-beheer']);

		$renamed = PortalAdminGrants::withPortal($rules, 'portal', 'zeist-nieuw', ['zeist-beheer'], 'zeist');
		$deleted = PortalAdminGrants::withPortal($rules, 'portal', '', [], 'zeist');

		$this->assertSame(['redactie', ['group' => 'zeist-beheer', 'match' => ['portal' => 'zeist-nieuw']]], $renamed['create']);
		$this->assertSame(self::PAGE_RULES, $deleted);
	}//end testARenamedOrDeletedPortalLosesItsRules()

	public function testAnEmptyActionGainsTheGrantAndAMissingOneIsLeftAlone(): void {
		$rules = PortalAdminGrants::withPortal(['read' => ['public'], 'create' => []], 'portal', 'zeist', ['zeist-beheer']);

		$grant = ['group' => 'zeist-beheer', 'match' => ['portal' => 'zeist']];
		$this->assertSame(['read' => ['public', $grant], 'create' => [$grant]], $rules);
		$this->assertArrayNotHasKey('update', $rules, 'A missing list is open to every signed-in user; a grant would close it.');
	}//end testAnEmptyActionGainsTheGrantAndAMissingOneIsLeftAlone()

	public function testGrantsInFindsOnlyTheRulesThisClassWrites(): void {
		$rules = [
			'redactie',
			['group' => 'public', 'match' => ['status' => 'published']],
			['group' => 'zeist-beheer', 'match' => ['portal' => 'zeist']],
			['group' => 'x', 'match' => ['portal' => 'zeist', 'status' => 'draft']],
		];

		$this->assertSame([['group' => 'zeist-beheer', 'match' => ['portal' => 'zeist']]], PortalAdminGrants::grantsIn($rules));
		$this->assertSame([], PortalAdminGrants::grantsIn('redactie'));
	}//end testGrantsInFindsOnlyTheRulesThisClassWrites()

	public function testApplyWritesEverySchemaThatExistsWithItsOwnField(): void {
		$page   = new GrantsSchemaDouble(self::PAGE_RULES);
		$portal = new GrantsSchemaDouble(['read' => ['portaliq-medewerkers']]);
		$mapper = new GrantsMapperDouble(['page' => $page, 'portal' => $portal]);

		$ok = $this->grants(mapper: $mapper)->apply('zeist', ['zeist-beheer']);

		$this->assertTrue($ok);
		$this->assertSame(['redactie', ['group' => 'zeist-beheer', 'match' => ['portal' => 'zeist']]], $page->authorization['update']);
		$this->assertSame(['portaliq-medewerkers', ['group' => 'zeist-beheer', 'match' => ['slug' => 'zeist']]], $portal->authorization['read']);
		$this->assertSame([$page, $portal], $mapper->updated);
	}//end testApplyWritesEverySchemaThatExistsWithItsOwnField()

	public function testApplyWritesNothingWhenTheRulesDoNotChange(): void {
		$mapper = new GrantsMapperDouble(['menu' => new GrantsSchemaDouble(['read' => ['public']])]);

		$this->assertTrue($this->grants(mapper: $mapper)->apply('', [], ''));
		$this->assertSame([], $mapper->updated);
	}//end testApplyWritesNothingWhenTheRulesDoNotChange()

	public function testApplyReportsAFailureAndLogsIt(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Portaliq: the portal administrators were not written into the schema rules',
			['reason' => 'no openregister']
		);

		$this->assertFalse((new PortalAdminGrants($container, $logger))->apply('zeist', ['zeist-beheer']));
	}//end testApplyReportsAFailureAndLogsIt()

	/**
	 * The grants over a mapper double.
	 *
	 * @param GrantsMapperDouble $mapper The mapper.
	 *
	 * @return PortalAdminGrants
	 */
	private function grants(GrantsMapperDouble $mapper): PortalAdminGrants {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Db\\SchemaMapper')->willReturn($mapper);

		return new PortalAdminGrants($container, $this->createMock(LoggerInterface::class));
	}//end grants()
}//end class

/**
 * A schema entity: its authorization block.
 */
class GrantsSchemaDouble {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $authorization The rules.
	 */
	public function __construct(public array $authorization) {
	}//end __construct()

	/**
	 * @return array<string, mixed>
	 */
	public function getAuthorization(): array {
		return $this->authorization;
	}//end getAuthorization()

	/**
	 * @param array<string, mixed> $authorization The rules.
	 *
	 * @return void
	 */
	public function setAuthorization(array $authorization): void {
		$this->authorization = $authorization;
	}//end setAuthorization()
}//end class

/**
 * OpenRegister's schema mapper: finds by slug, records updates.
 */
class GrantsMapperDouble {

	/**
	 * The schemas updated, in order.
	 *
	 * @var array<int, GrantsSchemaDouble>
	 */
	public array $updated = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, GrantsSchemaDouble> $schemas The schemas by slug.
	 */
	public function __construct(private readonly array $schemas) {
	}//end __construct()

	/**
	 * @param string $slug        The slug.
	 * @param string $application The app.
	 *
	 * @return GrantsSchemaDouble|null
	 */
	public function findByApplicationAndSlug(string $slug, string $application): ?GrantsSchemaDouble {
		return ($application === 'portaliq' ? ($this->schemas[$slug] ?? null) : null);
	}//end findByApplicationAndSlug()

	/**
	 * @param GrantsSchemaDouble $schema The schema.
	 *
	 * @return GrantsSchemaDouble
	 */
	public function update(GrantsSchemaDouble $schema): GrantsSchemaDouble {
		$this->updated[] = $schema;
		return $schema;
	}//end update()
}//end class
