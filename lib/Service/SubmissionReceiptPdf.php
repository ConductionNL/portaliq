<?php

/**
 * Portaliq Submission Receipt PDF
 *
 * Reads one of a subject's own receipt messages back and renders its PDF
 * through OpenRegister (capability-rows-without-a-requirement REQ-CRG-005).
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/specs/supplier-portal/spec.md#requirement-a-submission-receipt-offers-the-answers-as-a-pdf-req-crg-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Kernel\Contracts\PortalObjectReaderInterface;
use Closure;
use OCA\Portaliq\Kernel\Contracts\PortalPdfExportInterface;
use OCA\Portaliq\Kernel\Contracts\SubmissionReceiptServiceInterface;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The read and the render behind a receipt's PDF.
 *
 * @spec openspec/specs/supplier-portal/spec.md#requirement-a-submission-receipt-offers-the-answers-as-a-pdf-req-crg-005
 */
final class SubmissionReceiptPdf {

	/**
	 * The register the receipt messages live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema of a receipt message.
	 */
	private const MESSAGE_SCHEMA = 'portalMessage';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface                  $logger Records a receipt that could not be read.
	 * @param PortalObjectReaderInterface|null $reader Reads one receipt back for its own subject only.
	 * @param PortalPdfExportInterface|null    $pdf    Renders the receipt's PDF through OpenRegister.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?PortalObjectReaderInterface $reader = null,
		private readonly ?PortalPdfExportInterface $pdf = null,
	) {
	}//end __construct()

	/**
	 * The PDF of one of the subject's own receipts: the reference, the moment
	 * of receipt and the submitted answers from its data copy, rendered
	 * through OpenRegister. Someone else's receipt, an unknown id and a
	 * message that is no receipt all answer RECEIPT_NOT_FOUND, read before
	 * anything is rendered.
	 *
	 * @param array<string, mixed>     $subject    The resolved session subject.
	 * @param string                   $id         The receipt message's id, as the client sent it.
	 * @param IL10N                    $l10n       The portal's language.
	 * @param string                   $subjectKey The translatable title, with the reference as its one parameter.
	 * @param Closure(string): string  $readable   A moment as the reader reads it.
	 *
	 * @return array{status: string, bytes?: string, name?: string}
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#requirement-a-submission-receipt-offers-the-answers-as-a-pdf-req-crg-005
	 */
	public function pdfFor(array $subject, string $id, IL10N $l10n, string $subjectKey, Closure $readable): array {
		$message = $this->ownReceipt(subject: $subject, id: $id);
		if ($message === null) {
			return ['status' => SubmissionReceiptServiceInterface::RECEIPT_NOT_FOUND];
		}

		if ($this->available() === false) {
			return ['status' => SubmissionReceiptServiceInterface::RECEIPT_PDF_UNAVAILABLE];
		}

		$document = $this->document(
			message: $message,
			l10n: $l10n,
			title: $l10n->t($subjectKey, [(string)$message['referenceId']]),
			received: $readable((string)($message['receivedAt'] ?? ''))
		);
		$bytes    = $this->render(document: $document);
		if ($bytes === null) {
			return ['status' => SubmissionReceiptServiceInterface::RECEIPT_PDF_FAILED];
		}

		return ['status' => SubmissionReceiptServiceInterface::RECEIPT_PDF, 'bytes' => $bytes, 'name' => $document['title']];
	}//end pdfFor()

	/**
	 * What the receipt's PDF holds: the reference, the moment of receipt and
	 * every answer of the data copy, and nothing else of the message.
	 *
	 * @param array<string, mixed> $message  The receipt message.
	 * @param IL10N                $l10n     The portal's language.
	 * @param string               $title    The document's title.
	 * @param string               $received The moment of receipt, as the reader reads it.
	 *
	 * @return array{title: string, columns: array<int, array{key: string, label: string}>, rows: array<int, array<string, string>>}
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#requirement-a-submission-receipt-offers-the-answers-as-a-pdf-req-crg-005
	 */
	public function document(array $message, IL10N $l10n, string $title, string $received): array {
		$rows = [
			['part' => $l10n->t('Reference'), 'content' => (string)$message['referenceId']],
			['part' => $l10n->t('Received'), 'content' => $received],
		];
		foreach ((array)$message['dataCopy'] as $field => $answer) {
			$rows[] = ['part' => (string)$field, 'content' => $this->answerText(answer: $answer)];
		}

		return [
			'title'   => $title,
			'columns' => [['key' => 'part', 'label' => $l10n->t('Part')], ['key' => 'content', 'label' => $l10n->t('Content')]],
			'rows'    => $rows,
		];
	}//end document()

	/**
	 * Whether OpenRegister can render a PDF here.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#requirement-a-submission-receipt-offers-the-answers-as-a-pdf-req-crg-005
	 */
	public function available(): bool {
		return $this->pdf !== null && $this->pdf->available() === true;
	}//end available()

	/**
	 * The PDF bytes of a receipt document, or null when rendering failed or
	 * the document was too large.
	 *
	 * @param array<string, mixed> $document What document() gave: its title, columns and rows.
	 *
	 * @return string|null The bytes.
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#requirement-a-submission-receipt-offers-the-answers-as-a-pdf-req-crg-005
	 */
	public function render(array $document): ?string {
		if ($this->pdf === null) {
			return null;
		}

		$bytes = $this->pdf->render(title: $document['title'], columns: $document['columns'], rows: $document['rows']);
		if ($bytes === null || $bytes === PortalPdfExportInterface::TOO_LARGE) {
			return null;
		}

		return $bytes;
	}//end render()

	/**
	 * The subject's own receipt message, or null: read on `subjectRef` with
	 * the subject's own reference and organisation, as the inbox reads it,
	 * and only a message that carries a reference and a data copy.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 * @param string               $id      The message id.
	 *
	 * @return array<string, mixed>|null The receipt.
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#requirement-a-submission-receipt-offers-the-answers-as-a-pdf-req-crg-005
	 */
	public function ownReceipt(array $subject, string $id): ?array {
		$subjectRef = (string)($subject['subjectRef'] ?? '');
		if ($this->reader === null || $subjectRef === '' || $id === '') {
			return null;
		}

		try {
			$message = $this->reader->readObject(
				register: self::REGISTER,
				schema: self::MESSAGE_SCHEMA,
				scopeField: 'subjectRef',
				subjectRef: $subjectRef,
				id: $id,
				organisation: (string)($subject['organisation'] ?? ''),
				audience: (string)($subject['audience'] ?? '')
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: a receipt could not be read for its PDF', ['reason' => $e->getMessage()]);
			return null;
		}

		if (is_array($message) === false
			|| ($message['subjectRef'] ?? null) !== $subjectRef
			|| is_string($message['referenceId'] ?? null) === false
			|| $message['referenceId'] === ''
			|| is_array($message['dataCopy'] ?? null) === false
		) {
			return null;
		}

		return $message;
	}//end ownReceipt()

	/**
	 * One answer as text: a list joined by commas, a yes/no as `true`/`false`,
	 * anything else that is no scalar as nothing.
	 *
	 * @param mixed $answer The submitted answer.
	 *
	 * @return string The text.
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#requirement-a-submission-receipt-offers-the-answers-as-a-pdf-req-crg-005
	 */
	public function answerText(mixed $answer): string {
		if ($answer === true) {
			return 'true';
		}

		if ($answer === false) {
			return 'false';
		}

		if (is_array($answer) === true) {
			$parts = array_map(fn ($item): string => $this->answerText(answer: $item), $answer);

			return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
		}

		if (is_scalar($answer) === false) {
			return '';
		}

		return (string)$answer;
	}//end answerText()
}//end class
