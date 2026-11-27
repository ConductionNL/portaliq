<?php

/**
 * Portaliq Form Upload Grants
 *
 * The one-time upload token a landing-page form's anonymous submission gets
 * back, bound to the object it became (decision 25 Nov 2026 15:15 CET).
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
 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Kernel\Contracts\FormUploadGrantsInterface;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;

/**
 * Keeps each token in the distributed cache, by its hash, for thirty minutes.
 *
 * A token names one object and the file fields of its form, each with the
 * config its uploads are checked against. A field takes one file, or up to
 * ten when it allows several. The token itself is never stored: a cache dump
 * gives nobody a way to upload.
 *
 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
 */
final class FormUploadGrants implements FormUploadGrantsInterface {
	/**
	 * How long a token lives, in seconds: the same thirty minutes a signed-in
	 * create action may add files to what it created.
	 */
	public const TTL = 1800;

	/**
	 * Files per field when the field allows several.
	 */
	public const MAX_FILES = 10;

	/**
	 * The cache.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $caches Creates the distributed cache.
	 * @param ISecureRandom $random Mints the token.
	 */
	public function __construct(
		ICacheFactory $caches,
		private readonly ISecureRandom $random,
	) {
		$this->cache = $caches->createDistributed('portaliq-form-upload');
	}//end __construct()

	/**
	 * Mint the one-time upload token of a just-created submission.
	 *
	 * @param array{register: string, schema: string, id: string} $target  The object the submission became.
	 * @param array<string, array<string, mixed>>                 $uploads The file fields and their configs, by field id.
	 *
	 * @return string The token, '' when there is nothing to upload or no object.
	 *
	 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
	 */
	public function mint(array $target, array $uploads): string {
		if ($uploads === [] || $target['register'] === '' || $target['schema'] === '' || $target['id'] === '') {
			return '';
		}

		$left = [];
		foreach ($uploads as $field => $config) {
			$left[(string)$field] = $this->allowance(config: $config);
		}

		$token = $this->random->generate(48, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS));
		$this->cache->set($this->key(token: $token), ['target' => $target, 'uploads' => $uploads, 'left' => $left], self::TTL);

		return $token;
	}//end mint()

	/**
	 * What a token may still upload into one field, without using it.
	 *
	 * @param string $token The token.
	 * @param string $field The file field.
	 *
	 * @return array{target: array{register: string, schema: string, id: string}, config: array<string, mixed>}|null
	 *
	 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
	 */
	public function grantFor(string $token, string $field): ?array {
		$grant = $this->stored(token: $token);
		if ($grant === null || (int)($grant['left'][$field] ?? 0) < 1 || is_array($grant['uploads'][$field] ?? null) === false) {
			return null;
		}

		return ['target' => $grant['target'], 'config' => $grant['uploads'][$field]];
	}//end grantFor()

	/**
	 * Use one upload of the token's field. False when none is left.
	 *
	 * @param string $token The token.
	 * @param string $field The file field.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
	 */
	public function use(string $token, string $field): bool {
		$grant = $this->stored(token: $token);
		if ($grant === null || (int)($grant['left'][$field] ?? 0) < 1) {
			return false;
		}

		$grant['left'][$field] = ((int)$grant['left'][$field] - 1);
		if (array_sum($grant['left']) === 0) {
			$this->cache->remove($this->key(token: $token));
			return true;
		}

		$this->cache->set($this->key(token: $token), $grant, self::TTL);

		return true;
	}//end use()

	/**
	 * The stored grant of a token, or null.
	 *
	 * @param string $token The token.
	 *
	 * @return array<string, mixed>|null
	 */
	private function stored(string $token): ?array {
		if (trim($token) === '') {
			return null;
		}

		$grant = $this->cache->get($this->key(token: $token));
		if (is_array($grant) === false || is_array($grant['target'] ?? null) === false || is_array($grant['left'] ?? null) === false) {
			return null;
		}

		return $grant;
	}//end stored()

	/**
	 * How many files a field takes.
	 *
	 * @param array<string, mixed> $config The field's file config.
	 *
	 * @return int
	 */
	private function allowance(array $config): int {
		if (($config['multiple'] ?? false) === true) {
			return self::MAX_FILES;
		}

		return 1;
	}//end allowance()

	/**
	 * The cache key of a token: its hash, never the token.
	 *
	 * @param string $token The token.
	 *
	 * @return string
	 */
	private function key(string $token): string {
		return hash('sha256', $token);
	}//end key()
}//end class
