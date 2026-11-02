<?php

/**
 * A form placement is served with the help details of its stored form
 * (help-texts-and-form-help).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\FormPlacementHelp;
use OCA\Portaliq\Service\Cms\PortalHelp;
use OCA\Portaliq\Service\CmsRows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FormPlacementHelp::class)]
#[UsesClass(PortalHelp::class)]
class FormPlacementHelpTest extends TestCase {
	/**
	 * The help a stored form holds, as an editor saves it.
	 */
	private const STORED = [
		'phone'     => '14 021',
		'phoneNote' => 'Noem dat u hulp nodig heeft bij {formulier}.',
		'email'     => 'woo@zuiddrecht.example',
	];

	/**
	 * The fixture is a payload the real form schema accepts: every key is one
	 * the register declares, and the schema allows no others.
	 *
	 * @return void
	 */
	public function testTheStoredHelpIsWhatTheFormSchemaAccepts(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/portaliq_register.json'), true);
		$help     = $register['components']['schemas']['form']['properties']['help'];

		$this->assertSame('object', $help['type']);
		$this->assertFalse($help['additionalProperties']);
		foreach (self::STORED as $key => $value) {
			$this->assertSame('string', ($help['properties'][$key]['type'] ?? null), $key);
			$this->assertIsString($value);
		}
	}//end testTheStoredHelpIsWhatTheFormSchemaAccepts()

	/**
	 * The placement gets its own form's details; the forms are asked for by the serving portal, once.
	 *
	 * @return void
	 */
	public function testAPlacementGetsItsStoredFormsHelp(): void {
		$rows = $this->createMock(CmsRows::class);
		$rows->expects($this->once())
			->method('query')
			->with('form', ['portal' => 'zuiddrecht'])
			->willReturn([
				['id' => 'f-1', 'portal' => 'zuiddrecht', 'help' => self::STORED],
				['@self' => ['id' => 'f-2'], 'portal' => 'zuiddrecht', 'help' => ['desk' => 'Stadskantoor, Lindelaan 1', 'secret' => 'x']],
				['uuid' => 'f-3', 'portal' => 'zuiddrecht'],
			]);

		$out = (new FormPlacementHelp($rows))->attach(
			[
				['widgetKey' => 'markdown', 'props' => ['markdown' => 'Tekst']],
				['widgetKey' => 'form', 'props' => ['formId' => 'f-1', 'fields' => [['id' => 'a']]]],
				['widgetKey' => 'form', 'props' => ['formId' => ' f-2 ']],
				['widgetKey' => 'form', 'props' => ['formId' => 'f-3']],
				'junk',
			],
			'zuiddrecht'
		);

		$this->assertSame(['markdown' => 'Tekst'], $out[0]['props']);
		$this->assertSame(self::STORED, $out[1]['props']['formHelp']);
		$this->assertSame([['id' => 'a']], $out[1]['props']['fields'], 'the placement keeps its own props');
		$this->assertSame(['desk' => 'Stadskantoor, Lindelaan 1'], $out[2]['props']['formHelp'], 'only the fixed keys leave');
		$this->assertArrayNotHasKey('formHelp', $out[3]['props'], 'a form without help adds no key');
		$this->assertSame('junk', $out[4]);
	}//end testAPlacementGetsItsStoredFormsHelp()

	/**
	 * The stored form wins per key over what an author wrote on the placement, which is shaped too.
	 *
	 * @return void
	 */
	public function testTheStoredFormWinsOverThePlacement(): void {
		$rows = $this->createMock(CmsRows::class);
		$rows->method('query')->willReturn([['id' => 'f-1', 'portal' => 'zuiddrecht', 'help' => ['phone' => '14 021']]]);

		$out = (new FormPlacementHelp($rows))->attach(
			[['widgetKey' => 'form', 'props' => ['formId' => 'f-1', 'formHelp' => ['phone' => '14 020', 'hours' => 'Ma tot vr', 'image' => 'javascript:alert(1)']]]],
			'zuiddrecht'
		);

		$this->assertSame(['phone' => '14 021', 'hours' => 'Ma tot vr'], $out[0]['props']['formHelp']);
	}//end testTheStoredFormWinsOverThePlacement()

	/**
	 * A form of another portal is not read, and a malformed placement value does not leave.
	 *
	 * @return void
	 */
	public function testAForeignFormGivesNoHelp(): void {
		$rows = $this->createMock(CmsRows::class);
		// The read answers a row of another portal: it must not be used.
		$rows->method('query')->willReturn([['id' => 'f-1', 'portal' => 'noord', 'help' => self::STORED]]);

		$out = (new FormPlacementHelp($rows))->attach(
			[['widgetKey' => 'form', 'props' => ['formId' => 'f-1', 'formHelp' => 'tekst']]],
			'zuiddrecht'
		);

		$this->assertArrayNotHasKey('formHelp', $out[0]['props']);
	}//end testAForeignFormGivesNoHelp()

	/**
	 * No form on the page, no placement with an id, or no portal: nothing is read.
	 *
	 * @return void
	 */
	public function testNothingIsReadWithoutAFormToLookUp(): void {
		$rows = $this->createMock(CmsRows::class);
		$rows->expects($this->never())->method('query');
		$help = new FormPlacementHelp($rows);

		$this->assertSame([['widgetKey' => 'hero', 'props' => []]], $help->attach([['widgetKey' => 'hero', 'props' => []]], 'zuiddrecht'));
		$this->assertSame([['widgetKey' => 'form', 'props' => []]], $help->attach([['widgetKey' => 'form']], 'zuiddrecht'));
		$this->assertArrayNotHasKey('formHelp', $help->attach([['widgetKey' => 'form', 'props' => ['formId' => 'f-1']]], '')[0]['props']);
	}//end testNothingIsReadWithoutAFormToLookUp()

	/**
	 * A form inside an expanded shared block gets its help as well.
	 *
	 * @return void
	 */
	public function testAFormInsideASharedBlock(): void {
		$rows = $this->createMock(CmsRows::class);
		$rows->expects($this->once())->method('query')->willReturn([['id' => 'f-1', 'portal' => 'zuiddrecht', 'help' => self::STORED]]);

		$out = (new FormPlacementHelp($rows))->attach(
			[
				['widgetKey' => 'sharedBlock', 'props' => ['block' => 'b', 'unavailable' => false, 'widgets' => [['widgetKey' => 'form', 'props' => ['formId' => 'f-1']]]]],
				['widgetKey' => 'form', 'props' => ['formId' => 'f-1']],
			],
			'zuiddrecht'
		);

		$this->assertSame(self::STORED, $out[0]['props']['widgets'][0]['props']['formHelp']);
		$this->assertSame('b', $out[0]['props']['block']);
		$this->assertSame(self::STORED, $out[1]['props']['formHelp']);
	}//end testAFormInsideASharedBlock()
}//end class
