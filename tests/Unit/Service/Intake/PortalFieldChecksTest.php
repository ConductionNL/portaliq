<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DutchFormats;
use OCA\Portaliq\Service\Intake\PortalFieldChecks;
use OCA\Portaliq\Service\Intake\PortalFieldLimits;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Per-field answer validation: type, format, pattern, options and length.
 */
#[CoversClass(PortalFieldChecks::class)]
#[CoversClass(PortalFieldLimits::class)]
#[UsesClass(DutchFormats::class)]
class PortalFieldChecksTest extends TestCase {
	private PortalFieldChecks $checks;

	/**
	 * Build the checks with a pass-through translator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->checks = new PortalFieldChecks($l10n, new DutchFormats());
	}//end setUp()

	/**
	 * Numbers and emails are type-checked.
	 *
	 * @return void
	 */
	public function testNumberAndEmail(): void {
		$this->assertNotNull($this->checks->checkValue(['type' => 'number'], 'abc'));
		$this->assertNull($this->checks->checkValue(['type' => 'number'], '12.5'));
		$this->assertNotNull($this->checks->checkValue(['type' => 'email'], 'nope'));
		$this->assertNull($this->checks->checkValue(['type' => 'email'], 'a@b.nl'));
		$this->assertNull($this->checks->checkValue([], 'plain'));
	}//end testNumberAndEmail()

	/**
	 * A family-members answer is a list of partner-/child- references.
	 *
	 * @return void
	 */
	public function testFamilyMembers(): void {
		$field = ['type' => 'familyMembers'];
		$ref   = 'child-' . str_repeat('a1', 10);

		$this->assertNull($this->checks->checkValue($field, [$ref]));
		$this->assertNotNull($this->checks->checkValue($field, 'child'));
		$this->assertNotNull($this->checks->checkValue($field, ['k' => $ref]));
		$this->assertNotNull($this->checks->checkValue($field, ['child-xyz']));
		$this->assertNotNull($this->checks->checkValue($field, [5]));
	}//end testFamilyMembers()

	/**
	 * An address block must be complete.
	 *
	 * @return void
	 */
	public function testAddress(): void {
		$field = ['type' => 'addressNL'];
		$good  = ['postcode' => '1234 AB', 'number' => '12', 'street' => 'Dam', 'town' => 'Amsterdam'];

		$this->assertNull($this->checks->checkValue($field, $good));
		$this->assertNotNull($this->checks->checkValue($field, 'text'));
		$this->assertNotNull($this->checks->checkValue($field, ['street' => ' '] + $good));
		$this->assertNotNull($this->checks->checkValue($field, ['number' => '0'] + $good));
		$this->assertNotNull($this->checks->checkValue($field, ['postcode' => 'zzz'] + $good));
	}//end testAddress()

	/**
	 * A signature is a small PNG data URL.
	 *
	 * @return void
	 */
	public function testSignature(): void {
		$field = ['type' => 'signature'];
		$png   = "\x89PNG\r\n\x1a\n" . 'data';

		$this->assertNull($this->checks->checkValue($field, 'data:image/png;base64,' . base64_encode($png)));
		$this->assertNotNull($this->checks->checkValue($field, 12));
		$this->assertNotNull($this->checks->checkValue($field, 'data:image/png;base64,!!!'));
		$this->assertNotNull($this->checks->checkValue($field, 'data:image/png;base64,' . base64_encode('GIF89a')));
		$big = "\x89PNG\r\n\x1a\n" . str_repeat('x', 200001);
		$this->assertStringContainsString('too large', (string)$this->checks->checkValue($field, 'data:image/png;base64,' . base64_encode($big)));
	}//end testSignature()

	/**
	 * Known formats validate, report their own message, and are stored normalised.
	 *
	 * @return void
	 */
	public function testFormats(): void {
		$this->assertNull($this->checks->checkValue(['format' => 'postcode'], '1234ab'));
		$this->assertStringContainsString('postcode', (string)$this->checks->checkValue(['format' => 'postcode'], 'bad'));
		$this->assertNotNull($this->checks->checkValue(['format' => 'postcode'], 1234));
		$this->assertNull($this->checks->checkValue(['format' => 'unknown-format'], 'whatever'));

		$this->assertSame('1234 AB', $this->checks->stored(['format' => 'postcode'], '1234ab'));
		$this->assertSame('bad', $this->checks->stored(['format' => 'postcode'], 'bad'));
		$this->assertSame('x', $this->checks->stored([], 'x'));
		$this->assertSame(5, $this->checks->stored(['format' => 'postcode'], 5));
	}//end testFormats()

	/**
	 * Pattern, options and length limits.
	 *
	 * @return void
	 */
	public function testLimits(): void {
		$this->assertNotNull($this->checks->checkValue(['pattern' => '^a/b$'], 'ab'));
		$this->assertNull($this->checks->checkValue(['pattern' => '^a/b$'], 'a/b'));
		$this->assertNull($this->checks->checkValue(['pattern' => '^a$'], 5));

		$options = ['options' => [['value' => 'x'], 'y']];
		$this->assertNull($this->checks->checkValue($options, 'x'));
		$this->assertNull($this->checks->checkValue($options, 'y'));
		$this->assertNotNull($this->checks->checkValue($options, 'z'));
		$this->assertNotNull($this->checks->checkValue(['referenceListEmpty' => true], 'x'));
		$this->assertNull($this->checks->checkValue(['options' => []], 'x'));

		$this->assertNotNull($this->checks->checkValue(['maxLength' => 3], 'abcd'));
		$this->assertNull($this->checks->checkValue(['maxLength' => 3], 'abc'));
		$this->assertNull($this->checks->checkValue(['maxLength' => 0], 'abcdef'));
	}//end testLimits()
}//end class
