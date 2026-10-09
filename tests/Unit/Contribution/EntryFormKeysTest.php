<?php

/**
 * EntryFormKeys: an action form that asks for one entry of a day
 * (action-form-day-entry), as the BPV day form of the Esdoornveen board
 * MobielDetail asks it: two times, a pause as cards side by side, the hours
 * worked out, work processes as check cards, a photo, and a second button
 * that saves the entry for later.
 *
 * Runs the real normaliser chain (PortalManifestNormaliser) with a schema
 * reader double, the way ActionConfigNormaliserTest does.
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
 *
 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CreateBody;
use OCA\Portaliq\Contribution\EntryFormKeys;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Service\PortalSchemaReader;
use PHPUnit\Framework\TestCase;

/**
 * The day-entry keys of an action and its fields.
 */
class EntryFormKeysTest extends TestCase {
	/**
	 * The day form as learniq would declare it keeps every key it needs.
	 *
	 * @return void
	 */
	public function testTheDayFormKeepsItsKeys(): void {
		$action  = $this->dayAction();
		$configs = $action['fieldConfigs'];

		$this->assertSame('time', $configs['startTime']['input'], 'a format time field is a time input');
		$this->assertSame('time', $configs['stopTime']['input']);
		$this->assertSame('choices', $configs['pauseMinutes']['widget']);
		$this->assertTrue($configs['pauseMinutes']['inline']);
		$this->assertSame(['kind' => 'duration', 'from' => 'startTime', 'to' => 'stopTime', 'minus' => 'pauseMinutes'], $configs['hours']['computed']);
		$this->assertSame('checks', $configs['werkprocessen']['widget']);
		$this->assertSame(4, $configs['werkprocessen']['shown']);
		$this->assertSame('Foto toevoegen', $configs['photo']['buttonLabel']);
		$this->assertSame('code', $action['optionsProviders']['werkprocessen']['noteField']);
		$this->assertSame(['label' => 'Bewaren en later versturen', 'defaults' => ['lifecycle' => 'concept']], $action['saveForLater']);
		$this->assertSame('Petra keurt je uren goed of stuurt ze terug met een vraag.', $action['footnote']);
	}//end testTheDayFormKeepsItsKeys()

	/**
	 * What does not fit goes: check cards without options, a computed value
	 * that reads a field the action does not have or itself, an unknown kind,
	 * inline on cards that are no choice cards, a save for later without
	 * defaults or on another type of action, and texts that are too long.
	 *
	 * @return void
	 */
	public function testWhatDoesNotFitIsDropped(): void {
		$action = $this->dayAction(
			[
				'description'  => ['widget' => 'checks', 'shown' => 4, 'inline' => true],
				'hours'        => ['computed' => ['kind' => 'duration', 'from' => 'startTime', 'to' => 'endOfDay']],
				'pauseMinutes' => ['widget' => 'choices', 'computed' => ['kind' => 'duration', 'from' => 'pauseMinutes', 'to' => 'stopTime']],
				'stopTime'     => ['computed' => ['kind' => 'sum', 'from' => 'startTime', 'to' => 'hours']],
				'photo'        => ['type' => 'file', 'buttonLabel' => str_repeat('a', 201)],
			],
			['saveForLater' => ['label' => 'Later', 'defaults' => []], 'footnote' => ['not text']]
		);
		$configs = $action['fieldConfigs'];

		$this->assertArrayNotHasKey('widget', $configs['description'], 'check cards need options');
		$this->assertArrayNotHasKey('inline', $configs['description'], 'inline is for choice cards');
		$this->assertArrayNotHasKey('computed', $configs['hours'], 'a field the action does not have');
		$this->assertArrayNotHasKey('computed', $configs['pauseMinutes'], 'a value never reads itself');
		$this->assertArrayNotHasKey('computed', $configs['stopTime'], 'only a duration is known');
		$this->assertArrayNotHasKey('buttonLabel', $configs['photo']);
		$this->assertArrayNotHasKey('saveForLater', $action, 'save for later needs its defaults');
		$this->assertArrayNotHasKey('footnote', $action);

		$update = $this->dayAction([], ['type' => 'update', 'saveForLater' => ['label' => 'Later', 'defaults' => ['lifecycle' => 'concept']]]);
		$this->assertArrayNotHasKey('saveForLater', $update, 'only a create saves for later');
	}//end testWhatDoesNotFitIsDropped()

	/**
	 * The server works the hours out again from the times, so a typed total
	 * never stands; without times there is no total.
	 *
	 * @return void
	 */
	public function testTheServerWorksTheHoursOutAgain(): void {
		$action = $this->dayAction();
		$body   = (new CreateBody())->build(
			action: $action,
			whitelisted: ['startTime' => '08:00', 'stopTime' => '16:30', 'pauseMinutes' => 30, 'hours' => 40]
		);
		$this->assertSame(8.0, $body['hours']);
		$this->assertSame('submitted', $body['lifecycle'], 'the action\'s own default');

		$none = (new CreateBody())->build(action: $action, whitelisted: ['startTime' => '16:30', 'stopTime' => '08:00', 'hours' => 8]);
		$this->assertArrayNotHasKey('hours', $none, 'a stop before the start is no total');

		$this->assertSame(7.5, EntryFormKeys::hours(from: '08.00', to: '16.00', pause: '30'));
		$this->assertSame(1.33, EntryFormKeys::hours(from: '9:00', to: '10:20', pause: 0));
		$this->assertNull(EntryFormKeys::hours(from: '25:00', to: '26:00', pause: 0));
		$this->assertNull(EntryFormKeys::hours(from: '08:00', to: '', pause: 0));
		$this->assertNull(EntryFormKeys::hours(from: '08:00', to: '09:00', pause: 'lang'));
	}//end testTheServerWorksTheHoursOutAgain()

	/**
	 * Saved for later, the entry gets the action's `saveForLater` defaults
	 * over its own, so it waits until it is sent.
	 *
	 * @return void
	 */
	public function testSavedForLaterTheEntryWaits(): void {
		$body = (new CreateBody())->build(
			action: $this->dayAction(),
			whitelisted: ['startTime' => '08:00', 'stopTime' => '12:00', 'lifecycle' => 'approved'],
			saveForLater: true
		);

		$this->assertSame('concept', $body['lifecycle'], 'never what the client sent, never the send default');
		$this->assertSame(4.0, $body['hours']);
	}//end testSavedForLaterTheEntryWaits()

	/**
	 * The BPV day action, as learniq would declare it, through the real
	 * normaliser chain.
	 *
	 * @param array<string, array<string, mixed>> $configOverrides Field configs to replace.
	 * @param array<string, mixed>                $extra           Action keys to add or replace.
	 *
	 * @return array<string, mixed> The normalised action.
	 */
	private function dayAction(array $configOverrides = [], array $extra = []): array {
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->method('readSchema')->willReturn(
			[
				'required'   => ['bpvPlacementId', 'date', 'startTime', 'stopTime'],
				'properties' => [
					'bpvPlacementId' => ['type' => 'string'],
					'date'           => ['type' => 'string', 'format' => 'date'],
					'startTime'      => ['type' => 'string', 'format' => 'time'],
					'stopTime'       => ['type' => 'string', 'format' => 'time'],
					'pauseMinutes'   => ['type' => 'integer', 'enum' => [0, 30, 60]],
					'hours'          => ['type' => 'number'],
					'description'    => ['type' => 'string'],
					'werkprocessen'  => ['type' => 'array', 'items' => ['type' => 'string']],
					'photo'          => ['type' => 'string'],
				],
			]
		);

		$configs = array_merge(
			[
				'startTime'     => ['label' => 'Begonnen om', 'required' => true, 'size' => 'small'],
				'stopTime'      => ['label' => 'Gestopt om', 'required' => true, 'size' => 'small'],
				'pauseMinutes'  => ['label' => 'Pauze', 'widget' => 'choices', 'inline' => true],
				'hours'         => ['label' => 'Dat is vandaag', 'computed' => ['kind' => 'duration', 'from' => 'startTime', 'to' => 'stopTime', 'minus' => 'pauseMinutes']],
				'description'   => ['label' => 'Wat heb je gedaan?', 'help' => 'Eén of twee zinnen is genoeg.', 'size' => 'large'],
				'werkprocessen' => ['label' => 'Bij welk werkproces hoort dit?', 'help' => 'Je mag er meer kiezen.', 'widget' => 'checks', 'shown' => 4],
				'photo'         => ['label' => 'Foto', 'type' => 'file', 'buttonLabel' => 'Foto toevoegen', 'accept' => ['image/*']],
			],
			$configOverrides
		);

		$action = array_merge(
			[
				'id'               => 'writeHourDay',
				'type'             => 'create',
				'schema'           => 'bpv-hour-day',
				'register'         => 'learniq',
				'label'            => 'Uren schrijven',
				'submitLabel'      => 'Versturen naar je praktijkopleider',
				'fields'           => ['bpvPlacementId', 'date', 'startTime', 'stopTime', 'pauseMinutes', 'hours', 'description', 'werkprocessen', 'photo'],
				'fieldConfigs'     => $configs,
				'optionsProviders' => [
					'werkprocessen' => ['type' => 'collection', 'register' => 'learniq', 'schema' => 'werkproces', 'valueField' => 'id', 'labelField' => 'title', 'noteField' => 'code'],
				],
				'defaults'         => ['lifecycle' => 'submitted'],
				'saveForLater'     => ['label' => 'Bewaren en later versturen', 'defaults' => ['lifecycle' => 'concept']],
				'footnote'         => 'Petra keurt je uren goed of stuurt ze terug met een vraag.',
			],
			$extra
		);

		$out = (new PortalManifestNormaliser($schemaReader))->normalise(['collections' => [], 'actions' => [$action]]);
		return $out['actions'][0];
	}//end dayAction()
}//end class
