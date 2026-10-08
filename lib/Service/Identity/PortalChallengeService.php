<?php

/**
 * Portaliq Portal Challenge Service
 *
 * The challenge in front of a public form and of self-registration, run here
 * rather than at a vendor: a proof of work the visitor's own browser computes,
 * and a honeypot field a human never fills. Nothing leaves the instance, which
 * is the point: a municipality's intake form must not tell a third party who
 * is filling it in.
 *
 * The proof of work is a SHA-256 over the issued nonce and the visitor's
 * solution, which must carry a configured number of leading zero bits. The
 * work factor is per surface, so a form that is being hammered can be made
 * expensive without making every form expensive.
 *
 * The nonce is signed by this instance and carries the surface it was issued
 * for and the moment it stops counting. Without that it was not a credential
 * at all: nothing was stored, so a caller could invent their own nonce, solve
 * it once, and send the same pair for as long as they liked. Signing it is
 * what makes solving the work again the only way through.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateTimeImmutable;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

/**
 * Issues and verifies the portal's own challenge.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalChallengeService {
	/**
	 * The work factor used when a surface names none.
	 */
	public const DEFAULT_DIFFICULTY = 12;

	/**
	 * The ceiling on the work factor. Above this a phone spends minutes on a
	 * contact form, which refuses the visitor rather than the bot.
	 */
	public const MAX_DIFFICULTY = 24;

	/**
	 * How long a signed nonce stays usable. Long enough for somebody to fill
	 * a form in on a slow phone, short enough that one solved nonce is not a
	 * season ticket.
	 */
	public const TTL_SECONDS = 900;

	/**
	 * Constructor.
	 *
	 * @param ISecureRandom $random Mints the nonce.
	 * @param ICrypto $crypto Signs the nonce with the instance secret.
	 */
	public function __construct(
		private readonly ISecureRandom $random,
		private readonly ICrypto $crypto,
	) {
	}//end __construct()

	/**
	 * Whether this portal runs a challenge on this surface.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function isEnabled(array $site): bool {
		$proofOfWork = $this->proofOfWorkConfig(site: $site);

		return ($proofOfWork['enabled'] ?? false) === true;
	}//end isEnabled()

	/**
	 * Issue a challenge for a surface.
	 *
	 * The visitor gets the nonce, the moment it stops counting, and this
	 * instance's signature over both plus the surface. All three come back on
	 * the submission, and all three are checked there.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 * @param string $surface The surface asking, e.g. `form` or `registration`.
	 * @param DateTimeImmutable|null $now The moment to date the nonce from.
	 *
	 * @return array{nonce: string, expiresAt: int, signature: string, difficulty: int, algorithm: string}
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function issue(array $site, string $surface, ?DateTimeImmutable $now = null): array {
		$nonce = $this->random->generate(32, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
		$expiresAt = (($now ?? new DateTimeImmutable())->getTimestamp() + self::TTL_SECONDS);

		return [
			'nonce' => $nonce,
			'expiresAt' => $expiresAt,
			'signature' => $this->signatureFor(nonce: $nonce, surface: $surface, expiresAt: $expiresAt),
			'difficulty' => $this->difficultyFor(site: $site, surface: $surface),
			'algorithm' => 'sha256-leading-zero-bits',
		];
	}//end issue()

	/**
	 * This instance's signature over a nonce, its surface and its expiry.
	 *
	 * The instance secret is the key, so a nonce cannot be minted anywhere
	 * else, and the surface is inside the message, so a nonce issued for a
	 * cheap surface cannot be spent on an expensive one.
	 *
	 * @param string $nonce The nonce being signed.
	 * @param string $surface The surface it was issued for.
	 * @param int $expiresAt The unix second it stops counting.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function signatureFor(string $nonce, string $surface, int $expiresAt): string {
		return $this->crypto->calculateHMAC($nonce . ':' . $surface . ':' . $expiresAt);
	}//end signatureFor()

	/**
	 * Whether a nonce really came from here, for this surface, and still counts.
	 *
	 * @param string $nonce The nonce the visitor sent back.
	 * @param string $surface The surface they are submitting to.
	 * @param int $expiresAt The expiry they sent back.
	 * @param string $signature The signature they sent back.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function nonceIsOurs(string $nonce, string $surface, int $expiresAt, string $signature, ?DateTimeImmutable $now = null): bool {
		if ($nonce === '' || $signature === '') {
			return false;
		}

		if ($expiresAt <= ($now ?? new DateTimeImmutable())->getTimestamp()) {
			return false;
		}

		return hash_equals($this->signatureFor(nonce: $nonce, surface: $surface, expiresAt: $expiresAt), $signature);
	}//end nonceIsOurs()

	/**
	 * The work factor for a surface, clamped to something a phone can do.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 * @param string $surface The surface asking.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function difficultyFor(array $site, string $surface): int {
		$proofOfWork = $this->proofOfWorkConfig(site: $site);
		$difficulty = (int)($proofOfWork['difficulty'] ?? self::DEFAULT_DIFFICULTY);

		$surfaces = ($proofOfWork['surfaces'] ?? []);
		if (is_array($surfaces) === true && isset($surfaces[$surface]) === true) {
			$difficulty = (int)$surfaces[$surface];
		}

		if ($difficulty < 1) {
			return 1;
		}

		if ($difficulty > self::MAX_DIFFICULTY) {
			return self::MAX_DIFFICULTY;
		}

		return $difficulty;
	}//end difficultyFor()

	/**
	 * Whether a submission may proceed.
	 *
	 * The honeypot is checked first and on its own: it costs nothing, and a
	 * filled honeypot is a bot whatever else it sent.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 * @param string $surface The surface the submission came from.
	 * @param array<string, mixed> $submission The submitted fields, honeypot
	 *                                         included.
	 * @param string $nonce The nonce this visitor was issued.
	 * @param string $solution The visitor's solution.
	 * @param int $expiresAt The expiry issued with the nonce.
	 * @param string $signature This instance's signature over the nonce.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return bool True when the submission may proceed.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function accepts(
		array $site,
		string $surface,
		array $submission,
		string $nonce,
		string $solution,
		int $expiresAt = 0,
		string $signature = '',
		?DateTimeImmutable $now = null,
	): bool {
		if ($this->honeypotIsClean(site: $site, submission: $submission) === false) {
			return false;
		}

		if ($this->isEnabled(site: $site) === false) {
			return true;
		}

		// The signature comes first. A nonce this instance never issued costs
		// the caller nothing to invent, so checking the work on it would only
		// be checking work the caller chose to do.
		if ($this->nonceIsOurs(nonce: $nonce, surface: $surface, expiresAt: $expiresAt, signature: $signature, now: $now) === false) {
			return false;
		}

		return $this->solves(nonce: $nonce, solution: $solution, difficulty: $this->difficultyFor(site: $site, surface: $surface));
	}//end accepts()

	/**
	 * Whether the honeypot field was left alone.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 * @param array<string, mixed> $submission The submitted fields.
	 *
	 * @return bool
	 */
	public function honeypotIsClean(array $site, array $submission): bool {
		$challenge = (array)(((array)($site['authentication'] ?? []))['challenge'] ?? []);
		$field = (string)($challenge['honeypotField'] ?? '');
		if ($field === '') {
			return true;
		}

		return trim((string)($submission[$field] ?? '')) === '';
	}//end honeypotIsClean()

	/**
	 * Whether a solution carries enough leading zero bits.
	 *
	 * @param string $nonce The issued nonce.
	 * @param string $solution The visitor's solution.
	 * @param int $difficulty The number of leading zero bits required.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function solves(string $nonce, string $solution, int $difficulty): bool {
		if ($nonce === '' || $solution === '') {
			return false;
		}

		$digest = hash('sha256', ($nonce . ':' . $solution), true);

		return $this->leadingZeroBits(digest: $digest) >= $difficulty;
	}//end solves()

	/**
	 * How many leading zero bits a digest carries.
	 *
	 * @param string $digest The raw digest.
	 *
	 * @return int
	 */
	private function leadingZeroBits(string $digest): int {
		$bits = 0;
		$length = strlen($digest);
		for ($index = 0; $index < $length; $index++) {
			$byte = ord($digest[$index]);
			if ($byte === 0) {
				$bits += 8;
				continue;
			}

			for ($bit = 7; $bit >= 0; $bit--) {
				if ((($byte >> $bit) & 1) === 1) {
					return $bits;
				}

				$bits++;
			}

			return $bits;
		}

		return $bits;
	}//end leadingZeroBits()

	/**
	 * The proof-of-work configuration block, or an empty one.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 *
	 * @return array<string, mixed>
	 */
	private function proofOfWorkConfig(array $site): array {
		$challenge = (array)(((array)($site['authentication'] ?? []))['challenge'] ?? []);
		$proofOfWork = ($challenge['proofOfWork'] ?? []);
		if (is_array($proofOfWork) === false) {
			return [];
		}

		return $proofOfWork;
	}//end proofOfWorkConfig()
}//end class
