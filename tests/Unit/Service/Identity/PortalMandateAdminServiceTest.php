<?php

/**
 * The represented party manages who may act for it, a holder stops its own
 * mandate, and nobody is looked up (site-mandates-the-represented-manage).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Controller\MandateController;
use OCA\Portaliq\Service\Identity\PortalMandateAdminService;
use OCA\Portaliq\Service\Identity\PortalMandateService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class PortalMandateAdminServiceTest extends TestCase {
	use PortalIdentityStoreTrait;

	private const COMPANY = 'kvk:12345678';

	private const NOW = '2026-10-02T10:00:00+02:00';

	protected function setUp(): void {
		$this->rows = [];
	}//end setUp()

	private function service(): PortalMandateAdminService {
		return new PortalMandateAdminService($this->fakeReader(), $this->fakeWriter(), $this->fakeRandom());
	}//end service()

	private static function company(array $extra = []): array {
		return $extra + ['subjectRef' => 'jan-willem', 'provider' => 'eherkenning', 'kvk' => '12345678', 'organisation' => 'gemeente-x'];
	}//end company()

	private function now(): DateTimeImmutable {
		return new DateTimeImmutable(self::NOW);
	}//end now()

	public function testThePartyComesFromTheSessionAlone(): void {
		$this->assertSame('kvk:12345678', PortalMandateAdminService::partyOf(self::company()));
		$this->assertNull(PortalMandateAdminService::partyOf(self::company(['kvk' => ''])), 'an eHerkenning session without its company manages nothing');
		$this->assertNull(PortalMandateAdminService::partyOf(self::company(['kvk' => '123'])));
		$this->assertSame('subject:h-bakker', PortalMandateAdminService::partyOf(['subjectRef' => 'h-bakker', 'provider' => 'digid']));
		$this->assertNull(PortalMandateAdminService::partyOf(['provider' => 'digid']));
		$this->assertSame(['subject:jan-willem', 'kvk:12345678'], PortalMandateAdminService::holdersOf(self::company()));
		$this->assertSame(['subject:linda'], PortalMandateAdminService::holdersOf(['subjectRef' => 'linda', 'provider' => 'digid', 'kvk' => '12345678']));
	}

	public function testTypedFormReadsAnUntypedValue(): void {
		$this->assertSame('kvk:87654321', PortalMandateAdminService::typed('87654321'));
		$this->assertSame('subject:abc', PortalMandateAdminService::typed('abc'));
		$this->assertSame('kvk:87654321', PortalMandateAdminService::typed('kvk:87654321'));
		$this->assertSame('', PortalMandateAdminService::typed(' '));
	}

	public function testAnInvitationNeedsAnAddressAndAFutureEndDate(): void {
		$service = $this->service();

		$this->assertSame('bad_email', $service->invite(self::COMPANY, 'gemeente-x', 'jan-willem', ['email' => 'not an address'], $this->now()));
		$this->assertSame('bad_end_date', $service->invite(self::COMPANY, 'gemeente-x', 'jan-willem', ['email' => 'tom@example.nl', 'expiresAt' => '2026-01-01'], $this->now()));
		$this->assertSame('bad_end_date', $service->invite(self::COMPANY, 'gemeente-x', 'jan-willem', ['email' => 'tom@example.nl', 'expiresAt' => '2026-10-02'], $this->now()), 'today is not the future');
		$this->assertSame('bad_end_date', $service->invite(self::COMPANY, 'gemeente-x', 'jan-willem', ['email' => 'tom@example.nl', 'expiresAt' => '2026-02-31'], $this->now()));
		$this->assertSame([], $this->storedRows('portalInvitation'), 'nothing was sent');
	}

	public function testTomAcceptsAndTheMandateCarriesTheTerms(): void {
		$service = $this->service();
		$invited = $service->invite(self::COMPANY, 'gemeente-x', 'jan-willem', ['email' => 'tom@example.nl', 'caseTypes' => ['bezwaar'], 'label' => 'Mag alleen bezwaren indienen en volgen', 'expiresAt' => '2027-06-30'], $this->now());
		$this->assertIsArray($invited);

		$pending = $service->given(self::COMPANY, 'gemeente-x', $this->now());
		$this->assertSame('pending', $pending[0]['state']);
		$this->assertSame('tom@example.nl', $pending[0]['email']);

		$accepted = $service->accept($invited['token'], ['subjectRef' => 'tom', 'provider' => 'digid', 'organisation' => 'gemeente-x'], $this->now());

		$this->assertSame('subject:tom', $accepted['holder']);
		$mandate = $this->storedRows('portalMandate')[0];
		$this->assertSame('kvk:12345678', $mandate['onBehalfOf']);
		$this->assertSame('subject:tom', $mandate['holder']);
		$this->assertSame(['bezwaar'], $mandate['caseTypes']);
		$this->assertSame('jan-willem', $mandate['grantedBy']);
		$this->assertStringStartsWith('2027-06-30T23:59:59', $mandate['expiresAt']);

		$list = $service->given(self::COMPANY, 'gemeente-x', $this->now());
		$this->assertSame(['mandate'], array_column($list, 'kind'), 'the accepted invitation is no longer pending');
		$this->assertSame('active', $list[0]['state']);
	}

	public function testTheInviterCannotAcceptAndAnInvitationIsSpentOnce(): void {
		$service = $this->service();
		$invited = $service->invite(self::COMPANY, 'gemeente-x', 'jan-willem', ['email' => 'tom@example.nl'], $this->now());

		$this->assertSame('own_invitation', $service->accept($invited['token'], self::company(), $this->now()));
		$this->assertSame([], $this->storedRows('portalMandate'));

		$this->assertIsArray($service->accept($invited['token'], ['subjectRef' => 'tom', 'provider' => 'digid'], $this->now()));
		$this->assertSame('gone', $service->accept($invited['token'], ['subjectRef' => 'tom', 'provider' => 'digid'], $this->now()));
		$this->assertCount(1, $this->storedRows('portalMandate'), 'one accept, one mandate');
	}

	public function testARevokedOrExpiredInvitationCannotBeAccepted(): void {
		$service = $this->service();
		$invited = $service->invite(self::COMPANY, 'gemeente-x', 'jan-willem', ['email' => 'tom@example.nl'], $this->now());
		$id = $service->given(self::COMPANY, 'gemeente-x', $this->now())[0]['id'];

		$this->assertSame('', $service->revokeInvitation(self::COMPANY, 'gemeente-x', $id));
		$this->assertSame('gone', $service->accept($invited['token'], ['subjectRef' => 'tom', 'provider' => 'digid'], $this->now()));

		$late = $service->invite(self::COMPANY, 'gemeente-x', 'jan-willem', ['email' => 'anna@example.nl'], $this->now());
		$this->assertSame('gone', $service->accept($late['token'], ['subjectRef' => 'anna', 'provider' => 'digid'], new DateTimeImmutable('2026-12-01')), 'after the window');
		$this->assertSame('gone', $service->accept('unknown', ['subjectRef' => 'tom'], $this->now()));
	}

	public function testACompanyHoldsTheMandateItAccepts(): void {
		$service = $this->service();
		$invited = $service->invite('subject:h-bakker', 'gemeente-x', 'h-bakker', ['email' => 'kramer@example.nl'], $this->now());

		$accepted = $service->accept($invited['token'], self::company(['kvk' => '55555555', 'subjectRef' => 'clerk-1']), $this->now());

		$this->assertSame('kvk:55555555', $accepted['holder']);
		$this->assertCount(1, $service->held(['subject:someone-else', 'kvk:55555555'], 'gemeente-x', $this->now()));
		$this->assertSame([], $service->held(['kvk:12345678'], 'gemeente-x', $this->now()), 'another company does not carry it');
	}

	public function testAMandateWithoutHolderIsHeldByItsSubject(): void {
		$this->seedRow('portalMandate', ['subjectRef' => 'linda', 'organisation' => 'gemeente-x', 'onBehalfOf' => '87654321', 'status' => 'active']);
		$service = $this->service();

		$this->assertCount(1, $service->held(['subject:linda'], 'gemeente-x', $this->now()));
		$this->assertSame('subject:linda', $service->holderOf(['subjectRef' => 'linda']));
		$this->assertSame('subject:linda', $service->given('kvk:87654321', 'gemeente-x', $this->now())[0]['holder'], 'an old untyped party is listed under its typed form');
	}

	public function testAnotherPartysMandateIsNotFound(): void {
		$id = $this->seedRow('portalMandate', ['subjectRef' => 'kramer', 'organisation' => 'gemeente-x', 'onBehalfOf' => 'kvk:87654321', 'status' => 'active']);
		$service = $this->service();

		$this->assertSame('not_found', $service->revoke(self::COMPANY, 'gemeente-x', $id, 'jan-willem', $this->now()));
		$this->assertSame('not_found', $service->setExpiry(self::COMPANY, 'gemeente-x', $id, '2027-01-01', $this->now()));
		$this->assertSame('not_found', $service->revoke(self::COMPANY, 'gemeente-y', $id, 'jan-willem', $this->now()), 'nor in another tenant');
		$this->assertSame('active', $this->storedRows('portalMandate')[0]['status']);
	}

	public function testTheCompanyRevokesKramerAndTheHolderStopsItsOwn(): void {
		$kramer = $this->seedRow('portalMandate', ['subjectRef' => 'kramer', 'organisation' => 'gemeente-x', 'onBehalfOf' => '12345678', 'status' => 'active']);
		$linda = $this->seedRow('portalMandate', ['subjectRef' => 'linda', 'holder' => 'subject:linda', 'organisation' => 'gemeente-x', 'onBehalfOf' => 'subject:h-bakker', 'status' => 'active']);
		$service = $this->service();

		$this->assertSame('', $service->revoke(self::COMPANY, 'gemeente-x', $kramer, 'jan-willem', $this->now()));
		$this->assertSame('', $service->stop(['subject:linda'], 'gemeente-x', $linda, 'linda', $this->now()));

		$rows = $this->storedRows('portalMandate');
		$this->assertSame(['revoked', 'jan-willem'], [$rows[0]['status'], $rows[0]['revokedBy']]);
		$this->assertSame(['revoked', 'linda'], [$rows[1]['status'], $rows[1]['revokedBy']]);
		$this->assertNotEmpty($rows[1]['revokedAt']);
		$this->assertSame('not_found', $service->stop(['subject:mark'], 'gemeente-x', $linda, 'mark', $this->now()), 'only the holder stops it');

		$mandates = new PortalMandateService($this->fakeReader());
		$this->assertSame([], $mandates->mandatesFor('linda', 'gemeente-x', $this->now()), 'a revoked mandate grants nothing from the next read');
	}

	public function testAnEndedMandateCannotBeExtendedAndAPastDateIsRefused(): void {
		$active = $this->seedRow('portalMandate', ['subjectRef' => 'a', 'organisation' => 'gemeente-x', 'onBehalfOf' => 'kvk:12345678', 'status' => 'active']);
		$revoked = $this->seedRow('portalMandate', ['subjectRef' => 'b', 'organisation' => 'gemeente-x', 'onBehalfOf' => 'kvk:12345678', 'status' => 'revoked']);
		$expired = $this->seedRow('portalMandate', ['subjectRef' => 'c', 'organisation' => 'gemeente-x', 'onBehalfOf' => 'kvk:12345678', 'status' => 'active', 'expiresAt' => '2026-09-01T00:00:00+02:00']);
		$service = $this->service();

		$this->assertSame('bad_end_date', $service->setExpiry(self::COMPANY, 'gemeente-x', $active, '2026-10-01', $this->now()));
		$this->assertSame('gone', $service->setExpiry(self::COMPANY, 'gemeente-x', $revoked, '2027-01-01', $this->now()));
		$this->assertSame('gone', $service->setExpiry(self::COMPANY, 'gemeente-x', $expired, '2027-01-01', $this->now()));
		$this->assertSame('', $service->setExpiry(self::COMPANY, 'gemeente-x', $active, '2027-01-01', $this->now()));
		$this->assertStringStartsWith('2027-01-01T23:59:59', $this->storedRows('portalMandate')[0]['expiresAt']);
	}

	public function testTheControllerNamesThePartyFromTheSessionOnly(): void {
		$seen = [];
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturnCallback(static function () use (&$seen) {
			return $seen['subject'] ?? null;
		});
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer x');
		$controller = new MandateController($request, $session, $this->service());

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->given()->getStatus());

		$seen['subject'] = self::company(['provider' => 'eherkenning', 'kvk' => '']);
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->given()->getStatus(), 'no company number, no party');

		$seen['subject'] = self::company(['actingUnderMandate' => true]);
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->given()->getStatus(), 'a mandate does not manage mandates');

		$id = $this->seedRow('portalMandate', ['subjectRef' => 'kramer', 'organisation' => 'gemeente-x', 'onBehalfOf' => 'kvk:87654321', 'status' => 'active']);
		$seen['subject'] = self::company();
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->revoke($id)->getStatus());
		$this->assertSame('kvk:12345678', $controller->given()->getData()['party']);

		$bad = $controller->invite('tom@example.nl', [], 'x', '2020-01-01');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $bad->getStatus());
		$this->assertSame(['error' => 'bad_end_date'], $bad->getData());
		$this->assertArrayHasKey('token', $controller->invite('tom@example.nl', ['bezwaar'], 'x', '')->getData());
	}
}
