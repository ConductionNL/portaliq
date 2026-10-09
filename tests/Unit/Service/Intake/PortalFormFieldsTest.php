<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalFormFields;
use OCA\Portaliq\Service\Intake\PortalReferenceLists;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Preparing a form's declared fields: presets, ordering and reference lists.
 */
#[CoversClass(PortalFormFields::class)]
class PortalFormFieldsTest extends TestCase {
	/**
	 * Fields are filtered, given their preset and ordered.
	 *
	 * @return void
	 */
	public function testFieldsAreFilteredPresetAndOrdered(): void {
		$out = (new PortalFormFields())->fieldsOf([
			'fields' => [
				['name' => 'b', 'order' => 2],
				'junk',
				['order' => 1],
				['name' => 'a', 'order' => 1],
			],
			'presets' => ['a' => 'x'],
		]);

		$this->assertSame(['a', 'b'], array_column($out, 'name'));
		$this->assertSame('x', $out[0]['preset']);
		$this->assertArrayNotHasKey('preset', $out[1]);
		$this->assertSame([], (new PortalFormFields())->fieldsOf(['fields' => 'nope']));
		$this->assertSame([], (new PortalFormFields())->fieldsOf([]));
	}//end testFieldsAreFilteredPresetAndOrdered()

	/**
	 * A reference-list option is replaced by the list's items; an empty list is flagged.
	 *
	 * @return void
	 */
	public function testReferenceLists(): void {
		$lists = $this->createMock(PortalReferenceLists::class);
		$lists->method('items')->willReturnCallback(static fn (string $list): array => ($list === 'known' ? [['value' => 'a']] : []));
		$fields = new PortalFormFields($lists);

		$out = $fields->fieldsOf(['fields' => [
			['name' => 'k', 'options' => ['referenceList' => 'known']],
			['name' => 'e', 'options' => ['referenceList' => 'empty']],
			['name' => 'plain', 'options' => [['value' => 1]]],
		]]);

		$this->assertSame([['value' => 'a']], $out[0]['options']);
		$this->assertArrayNotHasKey('referenceListEmpty', $out[0]);
		$this->assertSame([], $out[1]['options']);
		$this->assertTrue($out[1]['referenceListEmpty']);
		$this->assertSame([['value' => 1]], $out[2]['options']);

		$none = (new PortalFormFields())->fieldsOf(['fields' => [['name' => 'e', 'options' => ['referenceList' => 'x']]]]);
		$this->assertTrue($none[0]['referenceListEmpty']);
	}//end testReferenceLists()
}//end class
