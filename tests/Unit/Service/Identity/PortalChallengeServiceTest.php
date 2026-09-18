<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-and-the-organisations-cases REQ-PIOC-005: the challenge runs
 * here. A submission without a valid solution is refused, the work factor is
 * per surface, and the honeypot refuses a bot that filled the field no human
 * sees. Nothing in this service makes a request anywhere, which is the
 * requirement's other half: there is no HTTP client to inject.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalChallengeServiceTest extends TestCase {

	public function testASubmissionWithoutASolvedChallengeIsRefused(): void {
		$service = $this->service();
		$site = $this->site(difficulty: 8);

		$this->assertFalse($service->accepts(site: $site, surface: 'form', submission: [], nonce: 'nonce-1', solution: 'nonsense'));
		$this->assertFalse($service->accepts(site: $site, surface: 'form', submission: [], nonce: 'nonce-1', solution: ''));

	}//end testASubmissionWithoutASolvedChallengeIsRefused()

	public function testASolvedChallengeIsAccepted(): void {
		$service = $this->service();
		$site = $this->site(difficulty: 8);
		$solution = $this->solve(nonce: 'nonce-1', difficulty: 8);

		$this->assertTrue($service->accepts(site: $site, surface: 'form', submission: [], nonce: 'nonce-1', solution: $solution));

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

		$this->assertFalse($service->accepts(site: $site, surface: 'form', submission: ['faxnummer' => '020-1234567'], nonce: 'nonce-1', solution: $solution));
		$this->assertTrue($service->accepts(site: $site, surface: 'form', submission: ['faxnummer' => ''], nonce: 'nonce-1', solution: $solution));

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

	}//end testTheIssuedChallengeNamesItsOwnAlgorithm()

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

		return new PortalChallengeService($random);
	}//end service()

}//end class
