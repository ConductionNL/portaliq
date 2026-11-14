<?php

/**
 * Portaliq Plan Templates Test
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Plans
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Plans;

use OCA\Portaliq\Service\Plans\PlanTemplates;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use PHPUnit\Framework\TestCase;

/**
 * A resident starts only from a published template of their own portal,
 * and a plan's title falls back to the template's.
 *
 * @covers \OCA\Portaliq\Service\Plans\PlanTemplates
 * @uses   \OCA\Portaliq\Service\Plans\PlanRules
 */
class PlanTemplatesTest extends TestCase {

	/**
	 * Templates over a reader that answers the given rows for portal "zuid".
	 *
	 * @param array<int, array<string, mixed>> $rows The template rows.
	 *
	 * @return PlanTemplates
	 */
	private function templates(array $rows): PlanTemplates {
		$reader = $this->createMock(ScopedCollectionReaderInterface::class);
		$reader->method('readCollection')->willReturnCallback(
			static fn (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200): array => ($register === 'portaliq' && $schema === 'portalPlanTemplate' && $scopeField === 'portal' && $subjectRef === 'zuid' && $organisation === '' && $limit === 100) ? $rows : []
		);

		return new PlanTemplates(reader: $reader);
	}//end templates()

	/**
	 * Three rows: published, a draft, and one with a truthy but non-boolean flag.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows(): array {
		return [
			['id' => 't1', 'title' => 'Schuldhulp op orde', 'published' => true],
			['id' => 't2', 'title' => 'Concept', 'published' => false],
			['id' => 't3', 'title' => 'Half', 'published' => 'yes'],
			['@self' => ['id' => 't4'], 'title' => 'Via envelope', 'published' => true],
		];
	}//end rows()

	public function testOnlyPublishedTemplatesOfThePortalAreOffered(): void {
		$templates = $this->templates(self::rows());

		$this->assertSame(['Schuldhulp op orde', 'Via envelope'], array_column($templates->published('zuid'), 'title'));
		$this->assertSame([], $templates->published('noord'));
		$this->assertSame([], $templates->published(''));
	}

	public function testATemplateIsFoundByIdOnlyWhenPublished(): void {
		$templates = $this->templates(self::rows());

		$this->assertSame('Schuldhulp op orde', $templates->byId('zuid', 't1')['title']);
		$this->assertSame('Via envelope', $templates->byId('zuid', 't4')['title']);
		$this->assertNull($templates->byId('zuid', 't2'));
		$this->assertNull($templates->byId('zuid', 'missing'));
		$this->assertNull($templates->byId('noord', 't1'));
	}

	public function testTheStartTitleIsTheGivenOneTrimmedElseTheTemplatesAndNeverTooLong(): void {
		$templates = $this->templates([]);
		$template  = ['title' => 'Schuldhulp op orde'];

		$this->assertSame('Mijn eigen plan', $templates->startTitle($template, '  Mijn eigen plan '));
		$this->assertSame('Schuldhulp op orde', $templates->startTitle($template, '   '));
		$this->assertSame('', $templates->startTitle(null, ''));
		$this->assertSame(str_repeat('é', 200), $templates->startTitle(null, str_repeat('é', 200)));
		$this->assertSame('', $templates->startTitle(null, str_repeat('é', 201)));
		$this->assertSame('', $templates->startTitle(['title' => str_repeat('a', 201)], ''));
	}
}
