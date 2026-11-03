<?php

/**
 * ActionDraftAnswersGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Intake\ActionDraft;
use OCA\Portaliq\Service\Intake\DestinationDraft;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;

/**
 * The answers a draft shows and the body a draft holds, pinned per action
 * field shape: declared fields, `claims`, file fields and odd entries.
 *
 * @covers \OCA\Portaliq\Service\Intake\ActionDraft
 * @uses   \OCA\Portaliq\Contribution\ActionScopeResolver
 * @uses   \OCA\Portaliq\Contribution\CreateBody
 */
class ActionDraftAnswersGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/action-draft-answers.json';

	private const ANSWERS = [
		'title'       => 'Titel',
		'description' => 'Omschrijving',
		'attachment'  => 'file-1',
		'claims'      => ['bsn' => '1'],
		'extra'       => 'not declared',
		'empty'       => null,
		'0'           => 'numeric key',
	];

	/**
	 * Every action's answers and body match the recorded ones.
	 *
	 * @return void
	 */
	public function testEveryAnswerSetMatchesTheGolden(): void {
		$drafts = $this->drafts();
		$actual = [];
		foreach ($this->actions() as $name => $action) {
			$actual[$name] = [
				'answersOf' => $drafts->answersOf(action: $action, draft: ['data' => self::ANSWERS]),
				'noData'    => $drafts->answersOf(action: $action, draft: []),
				'bodyOf'    => $drafts->bodyOf(match: ['action' => $action, 'app' => 'dossiq'], subject: ['subjectRef' => 'sub-1', 'organisation' => 'org-1'], answers: self::ANSWERS),
			];
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame($case, $actual[$name], $name);
		}
	}//end testEveryAnswerSetMatchesTheGolden()

	/**
	 * An action draft over doubles: the scope claim is the subject's ref, the stamp merges it in.
	 *
	 * @return ActionDraft
	 */
	private function drafts(): ActionDraft {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('resolveScopeValue')->willReturnCallback(
			static fn (string $scopeClaim, string $contributingApp, array $subject): ?string => (string)($subject['subjectRef'] ?? '')
		);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('stamp')->willReturnCallback(
			static fn (string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array => ['schema' => $schema, 'scopeField' => $scopeField, 'subjectRef' => $subjectRef, 'organisation' => $organisation, 'data' => $data]
		);

		return new ActionDraft(
			registry: $this->createMock(PortalContributionRegistry::class),
			reader: $reader,
			writer: $writer,
			drafts: $this->createMock(DestinationDraft::class)
		);
	}//end drafts()

	/**
	 * Create actions by name, one per field shape.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function actions(): array {
		$base = ['id' => 'request', 'type' => 'create', 'register' => 'dossiq', 'schema' => 'case'];

		return [
			'no fields'           => $base,
			'plain fields'        => $base + ['fields' => ['title', 'description']],
			'claims is dropped'   => $base + ['fields' => ['title', 'claims']],
			'file field dropped'  => $base + ['fields' => ['title', 'attachment'], 'fieldConfigs' => ['attachment' => ['type' => 'file']]],
			'non-file config'     => $base + ['fields' => ['title', 'attachment'], 'fieldConfigs' => ['attachment' => ['type' => 'text']]],
			'config not an array' => $base + ['fields' => ['title', 'attachment'], 'fieldConfigs' => ['attachment' => 'file']],
			'config without type' => $base + ['fields' => ['attachment'], 'fieldConfigs' => ['attachment' => []]],
			'odd field entries'   => $base + ['fields' => ['', 7, null, ['x'], 'title', 'title']],
			'undeclared answer'   => $base + ['fields' => ['extra', 'empty', '0']],
			'fields not a list'   => $base + ['fields' => 'title'],
			'configs not a list'  => $base + ['fields' => ['title'], 'fieldConfigs' => 'file'],
			'numeric config key'  => $base + ['fields' => ['0', 'title'], 'fieldConfigs' => [0 => ['type' => 'file']]],
			'defaults and scope'  => $base + ['fields' => ['title'], 'defaults' => ['caseType' => 'ct-1'], 'scopeField' => 'owner'],
		];
	}//end actions()
}//end class
