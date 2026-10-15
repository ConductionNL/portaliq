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
use OCA\Portaliq\Service\Identity\MandateParties;
use OCA\Portaliq\Service\Identity\PortalMandateAdminService;
use OCA\Portaliq\Service\Identity\PortalMandateService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Opis\JsonSchema\Validator;
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
		$this->assertSame('kvk:12345678', (new MandateParties())->partyOf(self::company()));
		$this->assertNull((new MandateParties())->partyOf(self::company(['kvk' => ''])), 'an eHerkenning session without its company manages nothing');
		$this->assertNull((new MandateParties())->partyOf(self::company(['kvk' => '123'])));
		$this->assertSame('subject:h-bakker', (new MandateParties())->partyOf(['subjectRef' => 'h-bakker', 'provider' => 'digid']));
		$this->assertNull((new MandateParties())->partyOf(['provider' => 'digid']));
		$this->assertSame(['subject:jan-willem', 'kvk:12345678'], (new MandateParties())->holdersOf(self::company()));
		$this->assertSame(['subject:linda'], (new MandateParties())->holdersOf(['subjectRef' => 'linda', 'provider' => 'digid', 'kvk' => '12345678']));
	}

	public function testTypedFormReadsAnUntypedValue(): void {
		$this->assertSame('kvk:87654321', (new MandateParties())->typed('87654321'));
		$this->assertSame('subject:abc', (new MandateParties())->typed('abc'));
		$this->assertSame('kvk:87654321', (new MandateParties())->typed('kvk:87654321'));
		$this->assertSame('', (new MandateParties())->typed(' '));
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

	/**
	 * What invite, accept and revoke store is what the real register schemas
	 * accept: a payload the schema refuses would pass every test above and
	 * never be saved on a live portal.
	 *
	 * @return void
	 */
	public function testTheStoredInvitationAndMandateFitTheRealSchemas(): void {
		$service = $this->service();
		$tom     = ['subjectRef' => 'tom', 'provider' => 'digid', 'organisation' => 'gemeente-x'];

		// With an end date and a scope, and without either.
		$full = $service->invite(self::COMPANY, 'gemeente-x', 'jan-willem', ['email' => 'tom@example.nl', 'caseTypes' => ['bezwaar'], 'label' => 'Mag alleen bezwaren indienen en volgen', 'expiresAt' => '2027-06-30'], $this->now());
		$bare = $service->invite(self::COMPANY, 'gemeente-x', 'jan-willem', ['email' => 'anna@example.nl'], $this->now());
		foreach ($this->storedRows('portalInvitation') as $row) {
			$this->assertSame([], $this->schemaProblems('portalInvitation', $row), 'a sent invitation');
		}

		$first = $service->accept($full['token'], $tom, $this->now());
		$service->accept($bare['token'], ['subjectRef' => 'anna', 'provider' => 'digid', 'organisation' => 'gemeente-x'], $this->now());
		$this->assertSame('', $service->revoke(self::COMPANY, 'gemeente-x', $first['id'], 'jan-willem', $this->now()));

		$mandates = $this->storedRows('portalMandate');
		$this->assertCount(2, $mandates);
		$this->assertSame('revoked', $mandates[0]['status']);
		$this->assertNotSame('', (string)$mandates[0]['invitationId']);
		foreach ($mandates as $row) {
			$this->assertSame([], $this->schemaProblems('portalMandate', $row), 'a mandate');
		}

		// The control: the check does refuse a state, a moment and a key the schema does not know.
		$base = ['subjectRef' => 'tom', 'organisation' => 'gemeente-x'];
		$this->assertSame(['enum at /status'], $this->schemaProblems('portalMandate', ($base + ['status' => 'paused'])));
		$this->assertSame(['format at /revokedAt'], $this->schemaProblems('portalMandate', ($base + ['revokedAt' => 'gisteren'])));
		$this->assertNotSame([], $this->schemaProblems('portalMandate', ($base + ['endedBy' => 'tom'])));

		foreach ($this->storedRows('portalInvitation') as $row) {
			$this->assertSame('accepted', $row['state']);
			$this->assertSame([], $this->schemaProblems('portalInvitation', $row), 'an accepted invitation');
		}
	}

	/**
	 * What the real schema in the register says against a stored row.
	 *
	 * An empty string on a property that is not required is left out first,
	 * as OpenRegister does before it validates (ValidateObject removes them).
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $row    The stored row.
	 *
	 * @return array<int, string> The keywords that failed; empty when the row fits.
	 */
	private function schemaProblems(string $schema, array $row): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/portaliq_register.json'), true);
		$declared = $register['components']['schemas'][$schema];
		$required = ($declared['required'] ?? []);
		foreach ($row as $key => $value) {
			$internal = (str_starts_with((string)$key, '_') === true || in_array($key, ['@self', 'id', 'uuid'], true) === true);
			if ($internal === true || ($value === '' && in_array($key, $required, true) === false)) {
				unset($row[$key]);
			}
		}

		$jsonSchema = json_decode(
			(string)json_encode(['type' => 'object', 'required' => $required, 'properties' => $declared['properties'], 'additionalProperties' => false]),
			false
		);
		$result     = (new Validator())->validate(json_decode((string)json_encode($row), false), $jsonSchema);
		if ($result->isValid() === true) {
			return [];
		}

		// The innermost error names the property and the keyword that refused it.
		$error = $result->error();
		while ($error->subErrors() !== []) {
			$error = $error->subErrors()[0];
		}

		return [$error->keyword() . ' at /' . implode('/', $error->data()->fullPath())];
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

	/**
	 * REQ-SMR-007: the holder reads who gave each mandate and when, from the
	 * rows the two real writers store, never the account that recorded it.
	 *
	 * @covers \OCA\Portaliq\Service\Identity\MandateListing
	 * @uses   \OCA\Portaliq\Service\Identity\MandateDays
	 * @uses   \OCA\Portaliq\Service\Identity\MandateParties
	 * @uses   \OCA\Portaliq\Service\Identity\MandateStore
	 * @uses   \OCA\Portaliq\Service\Identity\PortalAccessRequestService
	 * @uses   \OCA\Portaliq\Service\Identity\PortalMandateAdminService
	 *
	 * @return void
	 */
	public function testTheHolderSeesWhoGaveTheMandateAndWhen(): void {
		$service = $this->service();
		$invited = $service->invite(self::COMPANY, 'gemeente-x', 'jan-willem', ['email' => 'tom@example.nl'], $this->now());
		$service->accept($invited['token'], ['subjectRef' => 'tom', 'provider' => 'digid', 'organisation' => 'gemeente-x'], $this->now());

		$requests = new \OCA\Portaliq\Service\Identity\PortalAccessRequestService($this->fakeReader(), $this->fakeWriter());
		$request  = $requests->request(subjectRef: 'tom', organisation: 'gemeente-x', onBehalfOf: '87654321', reason: 'Ik doe de boekhouding');
		$requests->grant(id: $request['uuid'], organisation: 'gemeente-x', decidedBy: 'clerk-anna');

		foreach ($this->storedRows('portalMandate') as $row) {
			$this->assertSame([], $this->schemaProblems('portalMandate', $row), 'a stored mandate');
		}

		$held = $service->held(['subject:tom'], 'gemeente-x', $this->now());
		$this->assertSame(['kvk:12345678' => 'party', 'kvk:87654321' => 'organisation'], array_column($held, 'givenBy', 'onBehalfOf'));
		$this->assertSame('2026-10-02T10:00:00+02:00', $held[0]['grantedAt']);
		$this->assertNotSame('', $held[1]['grantedAt']);
		foreach ($held as $mandate) {
			$this->assertArrayNotHasKey('grantedBy', $mandate, 'the recording account stays out of the answer');
		}
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
		$mails  = [];
		$mailer = $this->createMock(\OCA\Portaliq\Service\Identity\PortalIdentityMailer::class);
		$mailer->method('send')->willReturnCallback(
			static function (string $template, string $email, string $secret) use (&$mails): bool {
				$mails[] = [$template, $email, $secret];
				return true;
			}
		);
		$controller = new MandateController(
			$request,
			$session,
			$this->service(),
			$mailer,
			$this->createMock(\OCA\Portaliq\Service\Identity\PortalAccountLookup::class),
			$this->createMock(\OCA\Portaliq\Service\Intake\PortalCaseTypeChoices::class),
			$this->createMock(\OCA\Portaliq\Service\PortalResolver::class)
		);

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
		$this->assertSame(['sent' => true], $controller->invite('tom@example.nl', ['bezwaar'], 'x', '')->getData());
		$this->assertSame('mandate-invitation', $mails[0][0]);
		$this->assertSame('tom@example.nl', $mails[0][1]);
		$stored = $this->storedRows('portalInvitation');
		$this->assertSame(hash('sha256', $mails[0][2]), end($stored)['tokenHash'], 'the mailed secret is the one the invitation stores, by hash');
	}
}
