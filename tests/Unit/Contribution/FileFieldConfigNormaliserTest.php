<?php

/**
 * FileFieldConfigNormaliser: the file field's multiple, accept and size keys.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\FileFieldConfigNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The file field config, key by key.
 */
class FileFieldConfigNormaliserTest extends TestCase {
	/**
	 * Apply a file config to a create action's field entry.
	 *
	 * @param array<string, mixed> $config The declared field config.
	 * @param string $actionType The action type.
	 *
	 * @return array<string, mixed>
	 */
	private function apply(array $config, string $actionType='create'): array {
		return (new FileFieldConfigNormaliser())->apply(entry: ['key' => 'proof'], config: $config, actionType: $actionType);
	}//end apply()

	/**
	 * Only `create` and `update` actions take files; others keep the entry untouched.
	 *
	 * @return void
	 */
	public function testOnlyCreateAndUpdateActionsTakeFiles(): void {
		$this->assertSame(expected: ['key' => 'proof', 'type' => 'file', 'multiple' => false], actual: $this->apply(config: ['type' => 'file']));
		$this->assertSame(expected: 'file', actual: $this->apply(config: ['type' => 'file'], actionType: 'update')['type']);
		foreach (['endpoint', 'delete', 'Create', ''] as $type) {
			$this->assertSame(expected: ['key' => 'proof'], actual: $this->apply(config: ['type' => 'file', 'multiple' => true], actionType: $type));
		}
	}//end testOnlyCreateAndUpdateActionsTakeFiles()

	/**
	 * A config that is not a file is left alone.
	 *
	 * @return void
	 */
	public function testANonFileConfigIsLeftAlone(): void {
		$this->assertSame(expected: ['key' => 'proof'], actual: $this->apply(config: ['type' => 'string', 'accept' => ['.pdf']]));
		$this->assertSame(expected: ['key' => 'proof'], actual: $this->apply(config: ['accept' => ['.pdf']]));
	}//end testANonFileConfigIsLeftAlone()

	/**
	 * The spellings of `multiple` that mean yes.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function multipleValues(): array {
		return [
			'true'          => [true, true],
			'string true'   => ['true', true],
			'one'           => [1, true],
			'false'         => [false, false],
			'string 1'      => ['1', false],
			'string yes'    => ['yes', false],
			'two'           => [2, false],
			'null'          => [null, false],
		];
	}//end multipleValues()

	/**
	 * `multiple` is true only for true, "true" and 1.
	 *
	 * @param mixed $declared The declared value.
	 * @param bool $expected Whether the field takes several files.
	 *
	 * @return void
	 */
	#[DataProvider('multipleValues')]
	public function testMultipleIsStrict(mixed $declared, bool $expected): void {
		$this->assertSame(expected: $expected, actual: $this->apply(config: ['type' => 'file', 'multiple' => $declared])['multiple']);
	}//end testMultipleIsStrict()

	/**
	 * Accept keeps extensions and mime types, lowercased, trimmed and without duplicates.
	 *
	 * @return void
	 */
	public function testAcceptKeepsExtensionsAndMimeTypes(): void {
		$entry = $this->apply(
			config: [
				'type'   => 'file',
				'accept' => [' .PDF ', '.pdf', 'image/*', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'IMAGE/PNG'],
			]
		);
		$this->assertSame(
			expected: ['.pdf', 'image/*', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/png'],
			actual: $entry['accept']
		);
	}//end testAcceptKeepsExtensionsAndMimeTypes()

	/**
	 * Accept values a browser would misread or a server could abuse are dropped.
	 *
	 * @return void
	 */
	public function testMalformedAcceptValuesAreDropped(): void {
		$entry = $this->apply(
			config: [
				'type'   => 'file',
				'accept' => ['pdf', '.', '.toolongextension123', '*/*', 'image/', '/png', 'image/png;q=1', '.p df', 7, null, ['.pdf']],
			]
		);
		$this->assertArrayNotHasKey(key: 'accept', array: $entry);
		$this->assertArrayNotHasKey(key: 'accept', array: $this->apply(config: ['type' => 'file', 'accept' => '.pdf']));
	}//end testMalformedAcceptValuesAreDropped()

	/**
	 * At most twenty accept values are kept, in declared order.
	 *
	 * @return void
	 */
	public function testAcceptIsCappedAtTwenty(): void {
		$declared = array_map(static fn (int $n): string => '.x'.$n, range(1, 25));
		$accept   = $this->apply(config: ['type' => 'file', 'accept' => $declared])['accept'];
		$this->assertCount(expectedCount: 20, haystack: $accept);
		$this->assertSame(expected: '.x1', actual: $accept[0]);
		$this->assertSame(expected: '.x20', actual: $accept[19]);
	}//end testAcceptIsCappedAtTwenty()

	/**
	 * Declared sizes and the size that is kept, clamped to 1..50 MB.
	 *
	 * @return array<string, array{0: mixed, 1: int|null}>
	 */
	public static function sizes(): array {
		return [
			'in range'       => [10, 10],
			'digit string'   => ['15', 15],
			'zero'           => [0, 1],
			'above maximum'  => [500, 50],
			'string above'   => ['51', 50],
			'negative'       => [-5, 1],
			'float'          => [2.5, null],
			'signed string'  => ['-5', null],
			'decimal string' => ['2.5', null],
			'empty string'   => ['', null],
			'null'           => [null, null],
		];
	}//end sizes()

	/**
	 * The size is kept only for whole numbers, and clamped.
	 *
	 * @param mixed $declared The declared size.
	 * @param int|null $expected The kept size, or null when it is dropped.
	 *
	 * @return void
	 */
	#[DataProvider('sizes')]
	public function testTheSizeIsClampedOrDropped(mixed $declared, ?int $expected): void {
		$entry = $this->apply(config: ['type' => 'file', 'maxSizeMb' => $declared]);
		$this->assertSame(expected: $expected, actual: ($entry['maxSizeMb'] ?? null));
	}//end testTheSizeIsClampedOrDropped()

	/**
	 * Keys the normaliser does not own are not copied from the config.
	 *
	 * @return void
	 */
	public function testUnknownConfigKeysAreNotCopied(): void {
		$entry = $this->apply(config: ['type' => 'file', 'path' => '/etc', 'storage' => 'anywhere']);
		$this->assertSame(expected: ['key', 'type', 'multiple'], actual: array_keys($entry));
	}//end testUnknownConfigKeysAreNotCopied()
}//end class
