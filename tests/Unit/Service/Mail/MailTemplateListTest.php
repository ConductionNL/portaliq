<?php

/**
 * Tests for the rows of the Mail templates screen (mail-templates-admin-screen T09).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Mail
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
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t09
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Mail;

use OCA\Portaliq\Kernel\Contracts\MailTemplateRendererInterface;
use OCA\Portaliq\Service\Mail\MailTemplateList;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Portaliq\Service\Mail\MailTemplateList
 */
class MailTemplateListTest extends TestCase {
	/**
	 * A fresh portal lists every kind once, with the default text.
	 *
	 * @return void
	 */
	public function testEveryKindIsListedWithoutStoredTexts(): void {
		$rows = MailTemplateList::merge(stored: []);

		self::assertSame(array_keys(MailTemplateRendererInterface::TEMPLATES), array_column($rows, 'templateKey'));
		$first = $rows[0];
		self::assertFalse($first['stored']);
		self::assertSame('default', $first['text']);
		self::assertSame('default-reference-link', $first['id']);
		self::assertSame('Link to follow a case', $first['kindLabel']);
		self::assertSame(2, $first['variableCount']);
		self::assertSame('', $first['updated']);

		$dutch = MailTemplateList::merge(stored: [], translate: static fn (string $text): string => 'nl:'.$text);
		self::assertSame('nl:Link to follow a case', $dutch[0]['kindLabel']);
	}//end testEveryKindIsListedWithoutStoredTexts()

	/**
	 * A stored text replaces its kind's default row, one row per stored text; an unknown kind is left out.
	 *
	 * @return void
	 */
	public function testStoredTextsReplaceTheDefaultRow(): void {
		$stored = [
			['@self' => ['id' => 'r1', 'updated' => '2026-11-20T10:00:00+00:00'], 'templateKey' => 'invitation', 'portal' => 'zuiddrecht', 'subject' => 'Welkom bij {portal}', 'body' => 'Open {link}', 'updatedBy' => 'admin'],
			['uuid' => 'r2', 'templateKey' => 'invitation', 'portal' => 'tilburg', 'subject' => 'Hallo', 'body' => 'x', 'active' => false],
			['id' => 'r3', 'templateKey' => 'no-such-kind', 'subject' => 'x', 'body' => 'x'],
			'not a row',
		];

		$rows    = MailTemplateList::merge(stored: $stored);
		$invites = array_values(array_filter($rows, static fn (array $row): bool => $row['templateKey'] === 'invitation'));

		self::assertCount(count(MailTemplateRendererInterface::TEMPLATES) + 1, $rows);
		self::assertSame(['r1', 'r2'], array_column($invites, 'id'));
		self::assertSame('Welkom bij {portal}', $invites[0]['subject']);
		self::assertSame('2026-11-20T10:00:00+00:00', $invites[0]['updated']);
		self::assertSame('own', $invites[0]['text']);
		self::assertTrue($invites[0]['stored']);
		self::assertTrue($invites[0]['active']);
		self::assertFalse($invites[1]['active']);
		self::assertNotContains('no-such-kind', array_column($rows, 'templateKey'));
	}//end testStoredTextsReplaceTheDefaultRow()

	/**
	 * The stored texts are read from OpenRegister; a failed read still lists every kind.
	 *
	 * @return void
	 */
	public function testRowsReadTheStoredTextsAndSurviveAFailedRead(): void {
		$service = new class {
			/**
			 * The config the last read asked for.
			 *
			 * @var array<string, mixed>
			 */
			public array $asked = [];

			/**
			 * Stand-in for ObjectService::findAll.
			 *
			 * @param array<string, mixed> $config        The read.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config, bool $_rbac=true, bool $_multitenancy=true): array {
				$this->asked = $config;
				return [new class {
					/**
					 * The row.
					 *
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return ['id' => 'r9', 'templateKey' => 'action-due', 'portal' => 'p', 'subject' => 'S', 'body' => 'B'];
					}//end jsonSerialize()
				},
				];
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);
		$rows = (new MailTemplateList($container, $this->createMock(LoggerInterface::class), $this->l10n()))->rows();

		self::assertSame(['register' => 'portaliq', 'schema' => 'portalMailTemplate'], $service->asked['filters']);
		self::assertContains('r9', array_column($rows, 'id'));

		$broken = $this->createMock(ContainerInterface::class);
		$broken->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		$fallback = (new MailTemplateList($broken, $this->createMock(LoggerInterface::class), $this->l10n()))->rows();
		self::assertCount(count(MailTemplateRendererInterface::TEMPLATES), $fallback);
	}//end testRowsReadTheStoredTextsAndSurviveAFailedRead()

	/**
	 * A translator that answers the text as given.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return $l10n;
	}//end l10n()
}//end class
