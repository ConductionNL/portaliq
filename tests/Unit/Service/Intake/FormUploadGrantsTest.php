<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\FormUploadGrants;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The one-time upload token of an anonymous form submission: bound to one
 * object and its form's file fields, one file per field (ten for a field that
 * takes several), kept by its hash for thirty minutes.
 *
 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
 */
class FormUploadGrantsTest extends TestCase {
	/**
	 * What the cache holds, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $data = [];

	/**
	 * The ttl of the last write.
	 *
	 * @var int
	 */
	private int $ttl = 0;

	/**
	 * A token names its object and field config, and a field takes one file.
	 *
	 * @return void
	 */
	public function testATokenGrantsOneFilePerFieldOfItsObject(): void {
		$grants = $this->grants();
		$token  = $grants->mint(target: $this->target(), uploads: ['photo' => ['type' => 'file', 'multiple' => false, 'maxSizeMb' => 5]]);

		$this->assertSame(expected: 'tok-abc', actual: $token);
		$this->assertSame(expected: FormUploadGrants::TTL, actual: $this->ttl);
		$this->assertSame(
			expected: ['target' => $this->target(), 'config' => ['type' => 'file', 'multiple' => false, 'maxSizeMb' => 5]],
			actual: $grants->grantFor(token: $token, field: 'photo')
		);
		$this->assertTrue(condition: $grants->use(token: $token, field: 'photo'));
		$this->assertNull(actual: $grants->grantFor(token: $token, field: 'photo'));
		$this->assertFalse(condition: $grants->use(token: $token, field: 'photo'));
		// Nothing left: the grant is gone from the cache.
		$this->assertSame(expected: [], actual: $this->data);
	}//end testATokenGrantsOneFilePerFieldOfItsObject()

	/**
	 * A field that takes several files takes ten.
	 *
	 * @return void
	 */
	public function testAFieldThatTakesSeveralTakesTen(): void {
		$grants = $this->grants();
		$token  = $grants->mint(target: $this->target(), uploads: ['papers' => ['type' => 'file', 'multiple' => true]]);

		for ($i = 0; $i < FormUploadGrants::MAX_FILES; $i++) {
			$this->assertTrue(condition: $grants->use(token: $token, field: 'papers'), message: 'file ' . ($i + 1));
		}

		$this->assertFalse(condition: $grants->use(token: $token, field: 'papers'));
	}//end testAFieldThatTakesSeveralTakesTen()

	/**
	 * Another field, another token or no token grants nothing, and the token
	 * itself is never a cache key.
	 *
	 * @return void
	 */
	public function testNothingElseIsGranted(): void {
		$grants = $this->grants();
		$token  = $grants->mint(target: $this->target(), uploads: ['photo' => ['type' => 'file']]);

		$this->assertNull(actual: $grants->grantFor(token: $token, field: 'name'));
		$this->assertNull(actual: $grants->grantFor(token: 'tok-other', field: 'photo'));
		$this->assertNull(actual: $grants->grantFor(token: '', field: 'photo'));
		$this->assertFalse(condition: $grants->use(token: $token, field: 'name'));
		$this->assertArrayNotHasKey(key: $token, array: $this->data);
		$this->assertArrayHasKey(key: hash('sha256', $token), array: $this->data);
	}//end testNothingElseIsGranted()

	/**
	 * Without file fields or without an object id no token is minted.
	 *
	 * @return void
	 */
	public function testNoTokenWithoutFilesOrObject(): void {
		$grants = $this->grants();

		$this->assertSame(expected: '', actual: $grants->mint(target: $this->target(), uploads: []));
		$this->assertSame(expected: '', actual: $grants->mint(target: ['register' => 'pipelinq', 'schema' => 'lead', 'id' => ''], uploads: ['photo' => ['type' => 'file']]));
		$this->assertSame(expected: [], actual: $this->data);
	}//end testNoTokenWithoutFilesOrObject()

	/**
	 * The object the grants are bound to.
	 *
	 * @return array{register: string, schema: string, id: string}
	 */
	private function target(): array {
		return ['register' => 'pipelinq', 'schema' => 'lead', 'id' => 'uuid-1'];
	}//end target()

	/**
	 * The grants over an in-memory cache.
	 *
	 * @return FormUploadGrants
	 */
	private function grants(): FormUploadGrants {
		$test  = $this;
		$cache = new class($test) implements ICache {
			public function __construct(private FormUploadGrantsTest $test) {
			}

			public function get($key) {
				return $this->test->cacheGet(key: $key);
			}

			public function set($key, $value, $ttl = 0) {
				$this->test->cacheSet(key: $key, value: $value, ttl: $ttl);
				return true;
			}

			public function hasKey($key) {
				return $this->get(key: $key) !== null;
			}

			public function remove($key) {
				$this->test->cacheRemove(key: $key);
				return true;
			}

			public function clear($prefix = '') {
				return true;
			}

			public static function isAvailable(): bool {
				return true;
			}
		};
		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$random = $this->createMock(originalClassName: ISecureRandom::class);
		$random->method('generate')->willReturn('tok-abc');

		return new FormUploadGrants(caches: $factory, random: $random);
	}//end grants()

	/**
	 * The cache's read.
	 *
	 * @param string $key The key.
	 *
	 * @return mixed
	 */
	public function cacheGet(string $key): mixed {
		return ($this->data[$key] ?? null);
	}//end cacheGet()

	/**
	 * The cache's write.
	 *
	 * @param string $key   The key.
	 * @param mixed  $value The value.
	 * @param int    $ttl   The ttl.
	 *
	 * @return void
	 */
	public function cacheSet(string $key, mixed $value, int $ttl): void {
		$this->data[$key] = $value;
		$this->ttl        = $ttl;
	}//end cacheSet()

	/**
	 * The cache's removal.
	 *
	 * @param string $key The key.
	 *
	 * @return void
	 */
	public function cacheRemove(string $key): void {
		unset($this->data[$key]);
	}//end cacheRemove()
}//end class
