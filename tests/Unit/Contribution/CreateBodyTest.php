<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use DateTimeImmutable;
use OCA\Portaliq\Contribution\CreateBody;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * A create action may name the field the server stamps with the moment it was made.
 *
 * @spec openspec/changes/a-create-stamps-the-moment-it-was-made/specs/portal-contribution-contract/spec.md#requirement-a-create-action-may-name-the-field-that-holds-when-it-was-made
 */
class CreateBodyTest extends TestCase {
	/**
	 * The named field carries the moment, over anything the client sent, beside the defaults.
	 *
	 * @return void
	 */
	public function testTheNamedFieldIsStampedWithTheMoment(): void {
		$body = (new CreateBody())->build(
			action: ['defaults' => ['ticketType' => 'request'], 'createdAtField' => 'occurredAt'],
			whitelisted: ['title' => 'Afval', 'occurredAt' => '1999-01-01T00:00:00+00:00'],
			now: new DateTimeImmutable('2026-10-16T07:41:00+00:00')
		);

		$this->assertSame(
			['title' => 'Afval', 'occurredAt' => '2026-10-16T07:41:00+00:00', 'ticketType' => 'request'],
			$body
		);
	}//end testTheNamedFieldIsStampedWithTheMoment()

	/**
	 * Without a sound field name nothing is stamped.
	 *
	 * @return void
	 */
	public function testNothingIsStampedWithoutASoundFieldName(): void {
		foreach ([null, '', 'a.b', '@self', 3] as $field) {
			$body = (new CreateBody())->build(action: ['createdAtField' => $field], whitelisted: ['title' => 'Afval']);
			$this->assertSame(['title' => 'Afval'], $body);
		}
	}//end testNothingIsStampedWithoutASoundFieldName()

	/**
	 * The declaration reaches the create path through the manifest normaliser.
	 *
	 * @return void
	 */
	public function testTheDeclarationSurvivesNormalisation(): void {
		$manifest = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [],
				'actions' => [
					['id' => 'askQuestion', 'type' => 'create', 'register' => 'pipelinq', 'schema' => 'ticket', 'fields' => ['title'], 'createdAtField' => 'occurredAt'],
				],
			]
		);

		$this->assertSame('occurredAt', $manifest['actions'][0]['createdAtField'] ?? null);
	}//end testTheDeclarationSurvivesNormalisation()
}//end class
