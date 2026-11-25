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
 * @spec openspec/changes/archive/2026-11-25-mail-templates-admin-screen/tasks.md#t09
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Mail;

use OCA\Portaliq\Kernel\Contracts\MailTemplateRendererInterface;
use OCA\Portaliq\Service\Mail\MailTemplateList;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Portaliq\Service\Mail\MailTemplateList
 */
class MailTemplateListTest extends TestCase {
	/**
	 * A fresh portal lists every kind once, with the default text, named in the reader's language.
	 *
	 * @return void
	 */
	public function testEveryKindIsListedWithoutStoredTexts(): void {
		$rows  = $this->list(container: $this->createMock(originalClassName: ContainerInterface::class))->merge(stored: []);
		$first = $rows[0];

		self::assertSame(expected: array_keys(MailTemplateRendererInterface::TEMPLATES), actual: array_column($rows, 'templateKey'));
		self::assertFalse(condition: $first['stored']);
		self::assertSame(expected: 'default', actual: $first['text']);
		self::assertSame(expected: 'default-reference-link', actual: $first['id']);
		self::assertSame(expected: 'Link to follow a case', actual: $first['kindLabel']);
		self::assertSame(expected: 2, actual: $first['variableCount']);
		self::assertSame(expected: '', actual: $first['updated']);

		$dutch = $this->list(container: $this->createMock(originalClassName: ContainerInterface::class))->merge(
			stored: [],
			translate: static fn (string $text): string => 'nl:'.$text
		);
		self::assertSame(expected: 'nl:Link to follow a case', actual: $dutch[0]['kindLabel']);
	}//end testEveryKindIsListedWithoutStoredTexts()

	/**
	 * A stored text replaces its kind's default row, one row per stored text; an unknown kind is left out.
	 *
	 * @return void
	 */
	public function testStoredTextsReplaceTheDefaultRow(): void {
		$stored = [
			[
				'@self' => ['id' => 'r1', 'updated' => '2026-11-20T10:00:00+00:00'],
				'templateKey' => 'invitation',
				'portal' => 'zuiddrecht',
				'subject' => 'Welkom bij {portal}',
				'body' => 'Open {link}',
				'updatedBy' => 'admin',
			],
			['uuid' => 'r2', 'templateKey' => 'invitation', 'portal' => 'tilburg', 'subject' => 'Hallo', 'body' => 'x', 'active' => false],
			['id' => 'r3', 'templateKey' => 'no-such-kind', 'subject' => 'x', 'body' => 'x'],
			'not a row',
		];

		$rows    = $this->list(container: $this->createMock(originalClassName: ContainerInterface::class))->merge(stored: $stored);
		$invites = array_values(array_filter($rows, static fn (array $row): bool => $row['templateKey'] === 'invitation'));

		self::assertCount(expectedCount: (count(MailTemplateRendererInterface::TEMPLATES) + 1), haystack: $rows);
		self::assertSame(expected: ['r1', 'r2'], actual: array_column($invites, 'id'));
		self::assertSame(expected: 'Welkom bij {portal}', actual: $invites[0]['subject']);
		self::assertSame(expected: '2026-11-20T10:00:00+00:00', actual: $invites[0]['updated']);
		self::assertSame(expected: 'own', actual: $invites[0]['text']);
		self::assertTrue(condition: $invites[0]['stored']);
		self::assertTrue(condition: $invites[0]['active']);
		self::assertFalse(condition: $invites[1]['active']);
		self::assertNotContains(needle: 'no-such-kind', haystack: array_column($rows, 'templateKey'));
	}//end testStoredTextsReplaceTheDefaultRow()

	/**
	 * The stored texts are read from OpenRegister; a failed read still lists every kind.
	 *
	 * @return void
	 */
	public function testRowsReadTheStoredTextsAndSurviveAFailedRead(): void {
		$service   = new MailTemplateStoreStub();
		$container = $this->createConfiguredMock(originalClassName: ContainerInterface::class, configuration: ['get' => $service]);
		$rows = $this->list(container: $container)->rows();

		self::assertSame(expected: 'portalMailTemplate', actual: $service->schema);
		self::assertContains(needle: 'r9', haystack: array_column($rows, 'id'));

		$fallback = $this->list(container: new MissingObjectServiceContainer())->rows();
		self::assertCount(expectedCount: count(MailTemplateRendererInterface::TEMPLATES), haystack: $fallback);
	}//end testRowsReadTheStoredTextsAndSurviveAFailedRead()

	/**
	 * A list over the given container, with a translator that answers 'label'.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's ObjectService.
	 *
	 * @return MailTemplateList
	 */
	private function list(ContainerInterface $container): MailTemplateList {
		return new MailTemplateList(
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			l10n: $this->createConfiguredMock(originalClassName: IL10N::class, configuration: ['t' => 'label'])
		);
	}//end list()
}//end class

/**
 * Stand-in for OpenRegister's ObjectService: one stored text.
 */
class MailTemplateStoreStub {
	/**
	 * The schema the last read asked for.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * Stand-in for ObjectService::setRegister.
	 *
	 * @param string $register The register.
	 *
	 * @return void
	 */
	public function setRegister(string $register): void {
		unset($register);
	}//end setRegister()

	/**
	 * Stand-in for ObjectService::setSchema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return void
	 */
	public function setSchema(string $schema): void {
		$this->schema = $schema;
	}//end setSchema()

	/**
	 * Stand-in for ObjectService::findAll.
	 *
	 * @param array<string, mixed> $config        The read.
	 * @param bool                 $_rbac         Ignored.
	 * @param bool                 $_multitenancy Ignored.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
	 */
	public function findAll(array $config, bool $_rbac=true, bool $_multitenancy=true): array {
		unset($config);

		return [['id' => 'r9', 'templateKey' => 'action-due', 'portal' => 'p', 'subject' => 'S', 'body' => 'B']];
	}//end findAll()
}//end class

/**
 * A container without OpenRegister.
 */
class MissingObjectServiceContainer implements ContainerInterface {
	/**
	 * Nothing resolves.
	 *
	 * @param string $id The service.
	 *
	 * @return mixed
	 *
	 * @throws NotFoundExceptionInterface Always.
	 */
	public function get(string $id): mixed {
		throw new class(message: 'no '.$id) extends RuntimeException implements NotFoundExceptionInterface {
		};
	}//end get()

	/**
	 * Nothing is there.
	 *
	 * @param string $id The service.
	 *
	 * @return bool
	 */
	public function has(string $id): bool {
		unset($id);

		return false;
	}//end has()
}//end class
