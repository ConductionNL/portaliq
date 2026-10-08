<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PortalChallengeService;
use DateTimeImmutable;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-and-the-organisations-cases REQ-PIOC-005: the challenge runs
 * here. A submission without a valid solution is refused, the work factor is
 * per surface, and the honeypot refuses a bot that filled the field no human
 * sees. Nothing in this service makes a request anywhere, which is the
 * requirement's other half: there is no HTTP client to inject.
 *
 * The nonce is signed by the instance, so a caller cannot bring their own.
 * gate-9 asked which credential the public registration endpoint
 * authenticates on, and until the signature existed the honest answer was
 * none: the nonce was never stored, so any string with enough leading zero
 * bits over it went through for ever.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalChallengeServiceTest extends TestCase {

	public function testASubmissionWithoutASolvedChallengeIsRefused(): void {
		$service = $this->service();
		$site = $this->site(difficulty: 8);

		$this->assertFalse($this->acceptsSigned(service: $service, site: $site, surface: 'form', submission: [], nonce: 'nonce-1', solution: 'nonsense'));
		$this->assertFalse($this->acceptsSigned(service: $service, site: $site, surface: 'form', submission: [], nonce: 'nonce-1', solution: ''));

	}//end testASubmissionWithoutASolvedChallengeIsRefused()

	public function testASolvedChallengeIsAccepted(): void {
		$service = $this->service();
		$site = $this->site(difficulty: 8);
		$solution = $this->solve(nonce: 'nonce-1', difficulty: 8);

		$this->assertTrue($this->acceptsSigned(service: $service, site: $site, surface: 'form', submission: [], nonce: 'nonce-1', solution: $solution));

	}//end testASolvedChallengeIsAccepted()

	public function testTheWorkFactorIsPerSurface(): void {
		$service = $this->service();
		$site = $this->site(difficulty: 8, surfaces: ['registration' => 14]);

		$this->assertSame(8, $service->difficultyFor(site: $site, surface: 'form'));
		$this->assertSame(14, $service->difficultyFor(site: $site, surface: 'registration'));

	}//end testTheWorkFactorIsPerSurface()

	public function testTheWorkFactorIsClampedToSomethingAPhoneCanDo(): void {
		$service = $this->service();

		$this->assertSame(PortalChallengeService::MAX_DIFFICULTY, $service->difficultyFor(site: $this->site(difficulty: 500), surface: 'form'));
		$this->assertSame(1, $service->difficultyFor(site: $this->site(difficulty: 0), surface: 'form'));

	}//end testTheWorkFactorIsClampedToSomethingAPhoneCanDo()

	public function testAFilledHoneypotIsRefusedWhateverElseWasSent(): void {
		$service = $this->service();
		$site = $this->site(difficulty: 8, honeypot: 'faxnummer');
		$solution = $this->solve(nonce: 'nonce-1', difficulty: 8);

		$this->assertFalse($this->acceptsSigned(service: $service, site: $site, surface: 'form', submission: ['faxnummer' => '020-1234567'], nonce: 'nonce-1', solution: $solution));
		$this->assertTrue($this->acceptsSigned(service: $service, site: $site, surface: 'form', submission: ['faxnummer' => ''], nonce: 'nonce-1', solution: $solution));

	}//end testAFilledHoneypotIsRefusedWhateverElseWasSent()

	public function testAPortalWithNoChallengeConfiguredAcceptsTheSubmission(): void {
		$service = $this->service();

		$this->assertTrue($service->accepts(site: [], surface: 'form', submission: [], nonce: '', solution: ''));

	}//end testAPortalWithNoChallengeConfiguredAcceptsTheSubmission()

	public function testTheIssuedChallengeNamesItsOwnAlgorithm(): void {
		$service = $this->service();

		$issued = $service->issue(site: $this->site(difficulty: 8), surface: 'form');

		$this->assertSame('sha256-leading-zero-bits', $issued['algorithm']);
		$this->assertSame(8, $issued['difficulty']);
		$this->assertNotSame('', $issued['nonce']);
		$this->assertNotSame('', $issued['signature']);
		$this->assertGreaterThan(time(), $issued['expiresAt']);

	}//end testTheIssuedChallengeNamesItsOwnAlgorithm()

	/**
	 * gate-9, and the reason the signature exists. Before it, nothing bound a
	 * nonce to this instance: the service minted one and stored nothing, so a
	 * caller could invent their own string, solve the work over it once, and
	 * send that pair for ever. A solved but unsigned nonce is now refused.
	 *
	 * @return void
	 */
	public function testANonceThisInstanceNeverIssuedIsRefusedHoweverWellItIsSolved(): void {
		$service = $this->service();
		$site = $this->site(difficulty: 8);
		$invented = 'i-was-never-issued-by-this-server';
		$solution = $this->solve(nonce: $invented, difficulty: 8);

		// The work really is done: the proof itself checks out.
		$this->assertTrue($service->solves(nonce: $invented, solution: $solution, difficulty: 8));

		$this->assertFalse($service->accepts(
			site: $site,
			surface: 'form',
			submission: [],
			nonce: $invented,
			solution: $solution,
			expiresAt: (time() + 600),
			signature: 'whatever-the-caller-felt-like'
		));

	}//end testANonceThisInstanceNeverIssuedIsRefusedHoweverWellItIsSolved()

	/**
	 * The surface is inside the signed message, so a nonce issued for a cheap
	 * surface cannot be spent on an expensive one.
	 *
	 * @return void
	 */
	public function testANonceIssuedForOneSurfaceIsNotGoodOnAnother(): void {
		$service = $this->service();
		$site = $this->site(difficulty: 8, surfaces: ['form' => 8, 'registration' => 8]);
		$solution = $this->solve(nonce: 'nonce-1', difficulty: 8);
		$forTheForm = $service->issue(site: $site, surface: 'form');

		$this->assertTrue($service->accepts(
			site: $site,
			surface: 'form',
			submission: [],
			nonce: 'nonce-1',
			solution: $solution,
			expiresAt: $forTheForm['expiresAt'],
			signature: $forTheForm['signature']
		));
		$this->assertFalse($service->accepts(
			site: $site,
			surface: 'registration',
			submission: [],
			nonce: 'nonce-1',
			solution: $solution,
			expiresAt: $forTheForm['expiresAt'],
			signature: $forTheForm['signature']
		));

	}//end testANonceIssuedForOneSurfaceIsNotGoodOnAnother()

	/**
	 * A solved nonce is not a season ticket: past its expiry it buys nothing,
	 * and the work has to be done again on a fresh one.
	 *
	 * @return void
	 */
	public function testASolvedNonceStopsCountingAtItsExpiry(): void {
		$service = $this->service();
		$site = $this->site(difficulty: 8);
		$solution = $this->solve(nonce: 'nonce-1', difficulty: 8);
		$issued = $service->issue(site: $site, surface: 'form', now: new DateTimeImmutable('@1000000000'));

		$stillFresh = $service->accepts(
			site: $site,
			surface: 'form',
			submission: [],
			nonce: 'nonce-1',
			solution: $solution,
			expiresAt: $issued['expiresAt'],
			signature: $issued['signature'],
			now: new DateTimeImmutable('@1000000001')
		);
		$this->assertTrue($stillFresh);

		$expired = $service->accepts(
			site: $site,
			surface: 'form',
			submission: [],
			nonce: 'nonce-1',
			solution: $solution,
			expiresAt: $issued['expiresAt'],
			signature: $issued['signature'],
			now: new DateTimeImmutable('@1000009999')
		);
		$this->assertFalse($expired);

	}//end testASolvedNonceStopsCountingAtItsExpiry()

	/**
	 * A submission carrying a nonce this service really issued, for the
	 * surface it is being spent on.
	 *
	 * @param PortalChallengeService $service The service under test.
	 * @param array<string, mixed> $site The portal.
	 * @param string $surface The surface.
	 * @param array<string, mixed> $submission The submitted fields.
	 * @param string $nonce The nonce.
	 * @param string $solution The solution offered.
	 *
	 * @return bool
	 */
	private function acceptsSigned(PortalChallengeService $service, array $site, string $surface, array $submission, string $nonce, string $solution): bool {
		$issued = $service->issue(site: $this->site(difficulty: 8), surface: $surface);

		return $service->accepts(
			site: $site,
			surface: $surface,
			submission: $submission,
			nonce: $nonce,
			solution: $solution,
			expiresAt: $issued['expiresAt'],
			signature: $issued['signature']
		);
	}//end acceptsSigned()

	/**
	 * Find a solution the way a visitor's browser would.
	 *
	 * @param string $nonce The issued nonce.
	 * @param int $difficulty The leading zero bits required.
	 *
	 * @return string
	 */
	private function solve(string $nonce, int $difficulty): string {
		$service = $this->service();
		for ($attempt = 0; $attempt < 200000; $attempt++) {
			$candidate = (string)$attempt;
			if ($service->solves(nonce: $nonce, solution: $candidate, difficulty: $difficulty) === true) {
				return $candidate;
			}
		}

		$this->fail('No solution found at difficulty ' . $difficulty);
	}//end solve()

	/**
	 * A portal declaring a challenge.
	 *
	 * @param int $difficulty The work factor.
	 * @param array<string, int> $surfaces Per-surface work factors.
	 * @param string $honeypot The honeypot field name, or ''.
	 *
	 * @return array<string, mixed>
	 */
	private function site(int $difficulty, array $surfaces = [], string $honeypot = ''): array {
		return [
			'authentication' => [
				'challenge' => [
					'proofOfWork' => ['enabled' => true, 'difficulty' => $difficulty, 'surfaces' => $surfaces],
					'honeypotField' => $honeypot,
				],
			],
		];
	}//end site()

	/**
	 * The service.
	 *
	 * @return PortalChallengeService
	 */
	private function service(): PortalChallengeService {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('nonce-1');

		// A real HMAC over a fixed key, so the test exercises the signature
		// rather than a stub that would agree with anything.
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(
			static function (string $message, string $password = ''): string {
				return hash_hmac('sha256', $message, ($password !== '' ? $password : 'instance-secret'));
			}
		);

		return new PortalChallengeService($random, $crypto);
	}//end service()

}//end class
