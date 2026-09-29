<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\PortalResolver;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * operate-show-per-case-type REQ-OSC-001 and REQ-OSC-002: the portal's own
 * list of hidden case types is the one predicate every enforcement point
 * asks, and the portal a signed-in request is served from is the one whose
 * list applies.
 *
 * @spec openspec/changes/operate-show-per-case-type/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
 */
class CaseTypeVisibilityTest extends TestCase {
	private const HIDING = [
		'slug' => 'mijn-alkmaar',
		'organisation' => 'alkmaar',
		'hiddenCaseTypes' => [
			['register' => 'dossiq', 'schema' => 'caseType', 'typeId' => 'handhaving', 'label' => 'Handhavingsdossier'],
			['typeId' => ''],
			'not-an-object',
		],
	];

	private const SHOWING = [
		'slug' => 'ondernemers-alkmaar',
		'organisation' => 'alkmaar',
	];

	public function testOnlyTheListedTypeIsHidden(): void {
		$visibility = $this->visibility();

		$this->assertSame(['handhaving'], $visibility->hiddenTypeIds(portal: self::HIDING));
		$this->assertTrue($visibility->isHidden(portal: self::HIDING, typeId: 'handhaving'));
		$this->assertFalse($visibility->isHidden(portal: self::HIDING, typeId: 'omgevingsvergunning'));
		$this->assertFalse($visibility->isHidden(portal: self::HIDING, typeId: ''));
		$this->assertFalse($visibility->isHidden(portal: self::SHOWING, typeId: 'handhaving'));
		$this->assertSame([], $visibility->hiddenTypeIds(portal: null));
	}//end testOnlyTheListedTypeIsHidden()

	public function testTheCaseTypeIsReadFromTheCollectionsField(): void {
		$visibility = $this->visibility();

		$this->assertSame('handhaving', $visibility->caseTypeOf(row: ['caseType' => 'handhaving'], collection: []));
		$this->assertSame('handhaving', $visibility->caseTypeOf(row: ['zaaktype' => ['id' => 'handhaving']], collection: ['caseTypeField' => 'zaaktype']));
		$this->assertSame('handhaving', $visibility->caseTypeOf(row: ['zaaktype' => ['uuid' => 'handhaving']], collection: ['caseTypeField' => 'zaaktype']));
		$this->assertSame('', $visibility->caseTypeOf(row: [], collection: []));

		$this->assertTrue($visibility->rowIsHidden(row: ['caseType' => 'handhaving'], collection: [], hidden: ['handhaving']));
		$this->assertFalse($visibility->rowIsHidden(row: ['caseType' => 'vergunning'], collection: [], hidden: ['handhaving']));
		$this->assertFalse($visibility->rowIsHidden(row: [], collection: [], hidden: ['handhaving']));
	}//end testTheCaseTypeIsReadFromTheCollectionsField()

	public function testTheNamedPortalOfTheSubjectsOrganisationApplies(): void {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturnCallback(
			static fn (IRequest $request, ?string $portalSlug = null): ?array => match ($portalSlug) {
				'mijn-alkmaar' => self::HIDING,
				'ondernemers-alkmaar' => self::SHOWING,
				default => null,
			}
		);
		$visibility = new CaseTypeVisibility($portals);

		$this->assertSame(['handhaving'], $visibility->hiddenForRequest(request: $this->request(header: 'mijn-alkmaar'), subject: ['organisation' => 'alkmaar']));
		// Another portal of the same organisation is not affected.
		$this->assertSame([], $visibility->hiddenForRequest(request: $this->request(header: 'ondernemers-alkmaar'), subject: ['organisation' => 'alkmaar']));
		// The query parameter names the portal when there is no header.
		$this->assertSame(['handhaving'], $visibility->hiddenForRequest(request: $this->request(param: 'mijn-alkmaar'), subject: ['organisation' => 'alkmaar']));
	}//end testTheNamedPortalOfTheSubjectsOrganisationApplies()

	public function testAPortalOfAnotherOrganisationFallsBackToTheSubjectsOwn(): void {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(['slug' => 'elders', 'organisation' => 'elders']);
		$portals->method('resolveByOrganisation')->willReturnCallback(
			static fn (string $organisation): ?array => ($organisation === 'alkmaar' ? self::HIDING : null)
		);
		$visibility = new CaseTypeVisibility($portals);

		$this->assertSame(['handhaving'], $visibility->hiddenForRequest(request: $this->request(header: 'elders'), subject: ['organisation' => 'alkmaar']));
	}//end testAPortalOfAnotherOrganisationFallsBackToTheSubjectsOwn()

	public function testAPortalIsFoundBySlugAmongThePublishedOnes(): void {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn([self::SHOWING, self::HIDING]);
		$visibility = new CaseTypeVisibility($portals);

		$this->assertSame(['handhaving'], $visibility->hiddenInPortal(slug: 'mijn-alkmaar'));
		$this->assertSame([], $visibility->hiddenInPortal(slug: 'ondernemers-alkmaar'));
		$this->assertSame([], $visibility->hiddenInPortal(slug: ''));
	}//end testAPortalIsFoundBySlugAmongThePublishedOnes()

	/**
	 * The service over a resolver that resolves nothing.
	 *
	 * @return CaseTypeVisibility
	 */
	private function visibility(): CaseTypeVisibility {
		return new CaseTypeVisibility($this->createMock(PortalResolver::class));
	}//end visibility()

	/**
	 * A request naming a portal by header or by query parameter.
	 *
	 * @param string $header The X-Portaliq-Portal header.
	 * @param string $param The ?portal= parameter.
	 *
	 * @return IRequest
	 */
	private function request(string $header = '', string $param = ''): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'X-Portaliq-Portal' ? $header : '')
		);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'portal' && $param !== '' ? $param : $default)
		);

		return $request;
	}//end request()
}//end class
