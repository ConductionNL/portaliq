<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\Portaliq\Listener\ChangeMessage;
use OCA\Portaliq\Listener\ChangeMessageWriter;
use OCA\Portaliq\Service\Notifications\PortalNoticeLanguage;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A change message lands in the subject's message box as an unread
 * `portalMessage` linked to the record, in the organisation's language
 * unless the rule wrote its own words; a refused write is logged by
 * collection, never by subject.
 *
 * @covers \OCA\Portaliq\Listener\ChangeMessageWriter
 * @uses   \OCA\Portaliq\Listener\ChangeMessage
 */
class ChangeMessageWriterTest extends TestCase {

	private const ACCOUNT = ['subjectRef' => 'sanne', 'organisation' => 'org-1'];

	private const LINK = ['app' => 'dossiq', 'collection' => 'case', 'id' => 'c-7'];

	/**
	 * What the writer was asked to create.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $created = [];

	/**
	 * What reached the logger.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The languages asked for, by organisation.
	 *
	 * @var array<int, string>
	 */
	private array $organisations = [];

	public function testTheDefaultWordsNameTheRecordInTheOrganisationsLanguage(): void {
		$this->writer()->write(account: self::ACCOUNT, message: new ChangeMessage(title: 'Bezwaar 7', recordLink: self::LINK, text: null));

		$this->assertCount(1, $this->created);
		$created = $this->created[0];
		$this->assertSame(['portaliq', 'portalMessage', 'subjectRef', 'sanne', 'org-1'], array_slice($created, 0, 5));
		$data = $created[5];
		$this->assertSame(['subject', 'body', 'read', 'receivedAt', 'recordLink'], array_keys($data));
		$this->assertSame('[org-1] Bezwaar 7 has been updated', $data['subject']);
		$this->assertSame('[org-1] Open it to see what changed.', $data['body']);
		$this->assertFalse($data['read']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $data['receivedAt']);
		$this->assertSame(self::LINK, $data['recordLink']);
		$this->assertSame(['org-1'], $this->organisations);
		$this->assertSame([], $this->logged);
	}//end testTheDefaultWordsNameTheRecordInTheOrganisationsLanguage()

	public function testTheRulesOwnWordsWinAndABlankOneFallsBack(): void {
		$this->writer()->write(account: self::ACCOUNT, message: new ChangeMessage(title: 'T', recordLink: self::LINK, text: ['subject' => 'Eigen onderwerp', 'body' => 'Eigen tekst']));
		$this->writer()->write(account: self::ACCOUNT, message: new ChangeMessage(title: 'T', recordLink: self::LINK, text: ['subject' => '', 'body' => 'Alleen tekst']));
		$this->writer()->write(account: self::ACCOUNT, message: new ChangeMessage(title: 'T', recordLink: self::LINK, text: ['subject' => 'Alleen onderwerp']));

		$this->assertSame(['Eigen onderwerp', 'Eigen tekst'], [$this->created[0][5]['subject'], $this->created[0][5]['body']]);
		$this->assertSame(['[org-1] T has been updated', 'Alleen tekst'], [$this->created[1][5]['subject'], $this->created[1][5]['body']]);
		$this->assertSame(['Alleen onderwerp', '[org-1] Open it to see what changed.'], [$this->created[2][5]['subject'], $this->created[2][5]['body']]);
	}//end testTheRulesOwnWordsWinAndABlankOneFallsBack()

	public function testAnAccountWithoutSubjectOrOrganisationIsWrittenBlank(): void {
		$this->writer()->write(account: [], message: new ChangeMessage(title: 'T', recordLink: self::LINK, text: null));

		$this->assertSame(['portaliq', 'portalMessage', 'subjectRef', '', ''], array_slice($this->created[0], 0, 5));
		$this->assertSame([''], $this->organisations);
	}//end testAnAccountWithoutSubjectOrOrganisationIsWrittenBlank()

	public function testARefusedWriteIsLoggedByCollectionOnly(): void {
		$this->writer(written: null)->write(account: self::ACCOUNT, message: new ChangeMessage(title: 'Bezwaar 7', recordLink: self::LINK, text: null));

		$this->assertSame([['Portaliq: change message was not written', ['app' => 'dossiq', 'collection' => 'case']]], $this->logged);
	}//end testARefusedWriteIsLoggedByCollectionOnly()

	/**
	 * The writer under test.
	 *
	 * @param array<string, mixed>|null $written What the object writer answers.
	 *
	 * @return ChangeMessageWriter
	 */
	private function writer(?array $written = ['id' => 'm-1']): ChangeMessageWriter {
		$objects = $this->createMock(PortalObjectWriter::class);
		$objects->method('createObject')->willReturnCallback(function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use ($written): ?array {
			$this->created[] = [$register, $schema, $scopeField, $subjectRef, $organisation, $data];
			return $written;
		});

		$language = $this->createMock(PortalNoticeLanguage::class);
		$language->method('forOrganisation')->willReturnCallback(function (string $organisation): IL10N {
			$this->organisations[] = $organisation;
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => '[' . $organisation . '] ' . vsprintf($text, (array)$parameters));
			return $l10n;
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->logged[] = [$message, $context];
		});

		return new ChangeMessageWriter(writer: $objects, language: $language, logger: $logger);
	}//end writer()
}//end class
