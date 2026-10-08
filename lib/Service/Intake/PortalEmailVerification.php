<?php

/**
 * Portaliq Portal Email Verification
 *
 * A one-time code mailed to the address a form asks for, and the signed proof
 * that the code was entered.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

/**
 * The code lives 15 minutes and allows 5 tries; a new one can be asked after
 * 60 seconds; an address and a client are throttled per hour. The code is kept
 * only as a hash. A right code is answered with a signed proof bound to the
 * portal, the form and the address, so the submit can check it without any
 * state and the proof works for no other address or form.
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */
class PortalEmailVerification {

	public const SENT = 'sent';

	public const INVALID = 'invalid';

	public const WAIT = 'wait';

	public const THROTTLED = 'throttled';

	public const UNAVAILABLE = 'unavailable';

	public const FAILED = 'failed';

	public const WRONG = 'wrong';

	public const EXPIRED = 'expired';

	public const TOO_MANY = 'too_many_tries';

	/**
	 * How long a code works, in seconds.
	 *
	 * @var int
	 */
	public const CODE_TTL = 900;

	/**
	 * How many wrong codes are allowed before the code is void.
	 *
	 * @var int
	 */
	public const MAX_TRIES = 5;

	/**
	 * How many seconds must pass before a new code can be asked.
	 *
	 * @var int
	 */
	public const RESEND_AFTER = 60;

	/**
	 * How long a proof of verification stays valid, in seconds.
	 *
	 * @var int
	 */
	private const PROOF_TTL = 7200;

	/**
	 * Codes one address may be sent in an hour.
	 *
	 * @var int
	 */
	private const PER_ADDRESS = 5;

	/**
	 * Codes one client may ask for in an hour.
	 *
	 * @var int
	 */
	private const PER_CLIENT = 20;

	/**
	 * The distributed cache, read on first use.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Holds the codes and the counters.
	 * @param ISecureRandom $random Mints the code.
	 * @param ICrypto $crypto Signs the proof with the instance secret.
	 * @param FormEmailCodeMailer $mailer Mails the code.
	 * @param IL10N $l10n The sentence a refusal is given with.
	 */
	public function __construct(
		private readonly ICacheFactory $cacheFactory,
		private readonly ISecureRandom $random,
		private readonly ICrypto $crypto,
		private readonly FormEmailCodeMailer $mailer,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Send a code to an address.
	 *
	 * @param array<string, mixed> $site The serving portal.
	 * @param string $route The form's route.
	 * @param string $email The address.
	 * @param string $client The client's address, for the throttle.
	 * @param int|null $now The unix second, for tests.
	 *
	 * @return string SENT, INVALID, WAIT, THROTTLED, UNAVAILABLE or FAILED.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	public function request(array $site, string $route, string $email, string $client, ?int $now=null): string {
		$cache = $this->store();
		$email = strtolower(trim($email));
		if ($cache === null) {
			return self::UNAVAILABLE;
		}

		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return self::INVALID;
		}

		$now  = ($now ?? time());
		$slug = (string)($site['slug'] ?? '');
		$key  = $this->keyOf(portal: $slug, route: $route, email: $email);
		$held = $cache->get('code:'.$key);
		if (is_array($held) === true && ($now - (int)($held['sentAt'] ?? 0)) < self::RESEND_AFTER) {
			return self::WAIT;
		}

		if ($this->spend(cache: $cache, name: 'address:'.hash('sha256', $email), limit: self::PER_ADDRESS, now: $now) === false
			|| $this->spend(cache: $cache, name: 'client:'.hash('sha256', $client), limit: self::PER_CLIENT, now: $now) === false
		) {
			return self::THROTTLED;
		}

		$code = $this->random->generate(6, ISecureRandom::CHAR_DIGITS);
		$cache->set('code:'.$key, ['hash' => $this->hashOf(key: $key, code: $code), 'sentAt' => $now, 'tries' => 0], self::CODE_TTL);
		if ($this->mailer->send(email: $email, code: $code, site: $site) === false) {
			$cache->remove('code:'.$key);
			return self::FAILED;
		}

		return self::SENT;
	}//end request()

	/**
	 * Check a code. A right code answers a proof; a wrong one counts as a try.
	 *
	 * @param string $portal The portal's slug.
	 * @param string $route The form's route.
	 * @param string $email The address.
	 * @param string $code What the resident typed.
	 * @param int|null $now The unix second, for tests.
	 *
	 * @return array{ok: bool, error: string, proof: string} The outcome.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	public function check(string $portal, string $route, string $email, string $code, ?int $now=null): array {
		$cache = $this->store();
		$email = strtolower(trim($email));
		if ($cache === null) {
			return ['ok' => false, 'error' => self::UNAVAILABLE, 'proof' => ''];
		}

		$now  = ($now ?? time());
		$key  = $this->keyOf(portal: $portal, route: $route, email: $email);
		$held = $cache->get('code:'.$key);
		if (is_array($held) === false || ($now - (int)($held['sentAt'] ?? 0)) > self::CODE_TTL) {
			return ['ok' => false, 'error' => self::EXPIRED, 'proof' => ''];
		}

		if ((int)($held['tries'] ?? 0) >= self::MAX_TRIES) {
			$cache->remove('code:'.$key);
			return ['ok' => false, 'error' => self::TOO_MANY, 'proof' => ''];
		}

		if (hash_equals((string)$held['hash'], $this->hashOf(key: $key, code: trim($code))) === false) {
			$held['tries'] = ((int)($held['tries'] ?? 0) + 1);
			$cache->set('code:'.$key, $held, self::CODE_TTL);
			return ['ok' => false, 'error' => self::WRONG, 'proof' => ''];
		}

		$cache->remove('code:'.$key);
		return ['ok' => true, 'error' => '', 'proof' => $this->proofFor(portal: $portal, route: $route, email: $email, now: $now)];
	}//end check()

	/**
	 * Whether a proof was issued here for this address on this form and still counts.
	 *
	 * @param string $portal The portal's slug.
	 * @param string $route The form's route.
	 * @param string $email The address.
	 * @param string $proof What the browser sent back.
	 * @param int|null $now The unix second, for tests.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	public function isVerified(string $portal, string $route, string $email, string $proof, ?int $now=null): bool {
		$parts = explode('.', $proof);
		if (count($parts) !== 2 || ctype_digit($parts[0]) === false) {
			return false;
		}

		$email = strtolower(trim($email));
		if ((int)$parts[0] < ($now ?? time())) {
			return false;
		}

		return hash_equals($this->sign(portal: $portal, route: $route, email: $email, expires: (int)$parts[0]), $parts[1]);
	}//end isVerified()

	/**
	 * The errors for each `verify` e-mail field whose answer has no valid proof.
	 *
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 * @param array<string, mixed> $answers The accepted answers.
	 * @param array<string, mixed> $proofs Proofs by address, as the browser sent them.
	 * @param string $portal The portal's slug.
	 * @param string $route The form's route.
	 *
	 * @return array<string, string> The errors by field name.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	public function unverified(array $fields, array $answers, array $proofs, string $portal, string $route): array {
		$errors = [];
		foreach ($fields as $field) {
			$name = (string)($field['name'] ?? '');
			if ($name === '' || (string)($field['type'] ?? '') !== 'email' || ($field['verify'] ?? false) !== true) {
				continue;
			}

			$address = strtolower(trim((string)($answers[$name] ?? '')));
			if ($address === '') {
				continue;
			}

			$proof = $proofs[$address] ?? '';
			if (is_string($proof) === false || $this->isVerified(portal: $portal, route: $route, email: $address, proof: $proof) === false) {
				$errors[$name] = $this->l10n->t('Check this e-mail address with the code we send you.');
			}
		}

		return $errors;
	}//end unverified()

	/**
	 * The verified addresses of a submission, to record with it.
	 *
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 * @param array<string, mixed> $answers The accepted answers, all of them verified.
	 * @param string $at The moment, as an ISO 8601 date-time.
	 *
	 * @return array<int, array{address: string, verifiedAt: string}> One line per verify field that was answered.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	public function record(array $fields, array $answers, string $at): array {
		$lines = [];
		foreach ($fields as $field) {
			$name = (string)($field['name'] ?? '');
			if ($name === '' || (string)($field['type'] ?? '') !== 'email' || ($field['verify'] ?? false) !== true) {
				continue;
			}

			$address = strtolower(trim((string)($answers[$name] ?? '')));
			if ($address !== '') {
				$lines[] = ['address' => $address, 'verifiedAt' => $at];
			}
		}

		return $lines;
	}//end record()

	/**
	 * The distributed cache, or null when this instance has none.
	 *
	 * @return ICache|null
	 */
	private function store(): ?ICache {
		if ($this->cache === null && $this->cacheFactory->isAvailable() === true) {
			$this->cache = $this->cacheFactory->createDistributed('portaliq-email-code');
		}

		return $this->cache;
	}//end store()

	/**
	 * The key of one code: portal, form and address together.
	 *
	 * @param string $portal The portal.
	 * @param string $route The form.
	 * @param string $email The address.
	 *
	 * @return string
	 */
	private function keyOf(string $portal, string $route, string $email): string {
		return hash('sha256', $portal."\n".$route."\n".$email);
	}//end keyOf()

	/**
	 * The hash a code is kept as.
	 *
	 * @param string $key The code's key.
	 * @param string $code The code.
	 *
	 * @return string
	 */
	private function hashOf(string $key, string $code): string {
		return hash('sha256', $key.':'.$code);
	}//end hashOf()

	/**
	 * Count one use against a limit for an hour.
	 *
	 * @param ICache $cache The cache.
	 * @param string $name The counter.
	 * @param int $limit The most uses.
	 * @param int $now The unix second.
	 *
	 * @return bool False when the limit is reached.
	 */
	private function spend(ICache $cache, string $name, int $limit, int $now): bool {
		$bucket = 'count:'.$name.':'.intdiv($now, 3600);
		$used   = (int)$cache->get($bucket);
		if ($used >= $limit) {
			return false;
		}

		$cache->set($bucket, ($used + 1), 3600);
		return true;
	}//end spend()

	/**
	 * A proof that an address was verified for a form.
	 *
	 * @param string $portal The portal.
	 * @param string $route The form.
	 * @param string $email The address.
	 * @param int $now The unix second.
	 *
	 * @return string `<expires>.<signature>`.
	 */
	private function proofFor(string $portal, string $route, string $email, int $now): string {
		$expires = ($now + self::PROOF_TTL);
		return $expires.'.'.$this->sign(portal: $portal, route: $route, email: $email, expires: $expires);
	}//end proofFor()

	/**
	 * The instance's signature over a proof.
	 *
	 * @param string $portal The portal.
	 * @param string $route The form.
	 * @param string $email The address.
	 * @param int $expires The unix second it stops counting.
	 *
	 * @return string
	 */
	private function sign(string $portal, string $route, string $email, int $expires): string {
		return $this->crypto->calculateHMAC('email-verified:'.$portal."\n".$route."\n".$email."\n".$expires);
	}//end sign()
}//end class
