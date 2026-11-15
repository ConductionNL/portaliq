<?php

/**
 * CtaBlockNormaliser: which call-to-action blocks survive and what is kept.
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

use OCA\Portaliq\Contribution\CtaBlockNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cta block: one target, a label, and its optional keys.
 */
class CtaBlockNormaliserTest extends TestCase {
	/**
	 * Normalise a block against a fixed set of action and page ids.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalise(array $block): ?array {
		return (new CtaBlockNormaliser())->normalise(
			block: $block,
			actionIds: ['apply', 'report'],
			pageIds: ['home', 'cases']
		);
	}//end normalise()

	/**
	 * Each kind of target that resolves is kept, and the label is trimmed.
	 *
	 * @return void
	 */
	public function testEachResolvingTargetIsKept(): void {
		$this->assertSame(
			expected: ['type' => 'cta', 'action' => 'apply', 'label' => 'Apply now'],
			actual: $this->normalise(block: ['action' => 'apply', 'label' => '  Apply now '])
		);
		$this->assertSame(
			expected: ['type' => 'cta', 'page' => 'cases', 'label' => 'My cases'],
			actual: $this->normalise(block: ['page' => 'cases', 'label' => 'My cases'])
		);
		$this->assertSame(
			expected: ['type' => 'cta', 'route' => '/mijn/zaken', 'label' => 'Go'],
			actual: $this->normalise(block: ['route' => '/mijn/zaken', 'label' => 'Go'])
		);
	}//end testEachResolvingTargetIsKept()

	/**
	 * Blocks a resident could never follow, or that are ambiguous.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function droppedBlocks(): array {
		return [
			'no label'              => [['action' => 'apply']],
			'label not a string'    => [['action' => 'apply', 'label' => 42]],
			'blank label'           => [['action' => 'apply', 'label' => "  \t "]],
			'label too long'        => [['action' => 'apply', 'label' => str_repeat('a', 121)]],
			'no target'             => [['label' => 'Go']],
			'two targets'           => [['action' => 'apply', 'page' => 'home', 'label' => 'Go']],
			'unknown action'        => [['action' => 'delete-everything', 'label' => 'Go']],
			'unknown page'          => [['page' => 'admin', 'label' => 'Go']],
			'relative route'        => [['route' => 'mijn/zaken', 'label' => 'Go']],
			'protocol-relative'     => [['route' => '//evil.example/x', 'label' => 'Go']],
			'double slash inside'   => [['route' => '/mijn//zaken', 'label' => 'Go']],
			'absolute url'          => [['route' => 'https://evil.example/', 'label' => 'Go']],
			'query string in route' => [['route' => '/zoek?q=x', 'label' => 'Go']],
			'route not a string'    => [['route' => ['/x'], 'label' => 'Go']],
			'action id loose match' => [['action' => 0, 'label' => 'Go']],
		];
	}//end droppedBlocks()

	/**
	 * A malformed or unreachable block is dropped, never rewritten.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return void
	 */
	#[DataProvider('droppedBlocks')]
	public function testAMalformedBlockIsDropped(array $block): void {
		$this->assertNull(actual: $this->normalise(block: $block));
	}//end testAMalformedBlockIsDropped()

	/**
	 * A label of exactly the maximum length still counts.
	 *
	 * @return void
	 */
	public function testALabelAtTheLimitIsKept(): void {
		$label = str_repeat('é', 120);
		$this->assertSame(expected: $label, actual: $this->normalise(block: ['page' => 'home', 'label' => $label])['label']);
	}//end testALabelAtTheLimitIsKept()

	/**
	 * A null target does not count as a second target.
	 *
	 * @return void
	 */
	public function testANullTargetIsIgnored(): void {
		$this->assertSame(
			expected: ['type' => 'cta', 'page' => 'home', 'label' => 'Home'],
			actual: $this->normalise(block: ['action' => null, 'page' => 'home', 'route' => null, 'label' => 'Home'])
		);
	}//end testANullTargetIsIgnored()

	/**
	 * The optional keys come along only when well formed, after the target.
	 *
	 * @return void
	 */
	public function testOptionalKeysAreKeptWhenWellFormed(): void {
		$this->assertSame(
			expected: [
				'type'           => 'cta',
				'action'         => 'report',
				'label'          => 'Report',
				'withRecord'     => true,
				'labelWhenEmpty' => 'Nothing yet',
				'messageTo'      => 'case_handler_1',
			],
			actual: $this->normalise(
				block: [
					'action'         => 'report',
					'label'          => 'Report',
					'withRecord'     => true,
					'labelWhenEmpty' => ' Nothing yet ',
					'messageTo'      => 'case_handler_1',
				]
			)
		);
	}//end testOptionalKeysAreKeptWhenWellFormed()

	/**
	 * Optional keys that are malformed are dropped, the block itself survives.
	 *
	 * @return void
	 */
	public function testMalformedOptionalKeysAreDropped(): void {
		$expected = ['type' => 'cta', 'action' => 'apply', 'label' => 'Apply'];
		$declared = [
			['withRecord' => 'true', 'labelWhenEmpty' => ' ', 'messageTo' => 'case-handler'],
			['labelWhenEmpty' => str_repeat('x', 121), 'messageTo' => str_repeat('a', 65)],
			['labelWhenEmpty' => ['x'], 'messageTo' => ''],
		];
		foreach ($declared as $extra) {
			$this->assertSame(expected: $expected, actual: $this->normalise(block: ['action' => 'apply', 'label' => 'Apply'] + $extra));
		}
	}//end testMalformedOptionalKeysAreDropped()

	/**
	 * The root route and percent-encoded routes are allowed.
	 *
	 * @return void
	 */
	public function testRootAndEncodedRoutesAreAllowed(): void {
		$this->assertSame(expected: '/', actual: $this->normalise(block: ['route' => '/', 'label' => 'Start'])['route']);
		$route = '/zoek/%C3%A9n_~.x-y';
		$this->assertSame(expected: $route, actual: $this->normalise(block: ['route' => $route, 'label' => 'Zoek'])['route']);
	}//end testRootAndEncodedRoutesAreAllowed()
}//end class
