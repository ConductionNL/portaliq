<?php

/**
 * Contract of ReportThreadService
 *
 * The public surface of ReportThreadService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of ReportThreadService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportThreadServiceInterface {

	/**
	 * Every message on a thread, for the handler's own view.
	 *
	 * @param string $reportId The report.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	public function allMessages(string $reportId): array;

	/**
	 * The thread as the reporter may read it.
	 *
	 * @param string $reportId The report.
	 *
	 * @return array<int, array<string, mixed>> Only the messages declared
	 *         visible to the reporter, oldest first.
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	public function messagesForReporter(string $reportId): array;

	/**
	 * The report a code opens, or null.
	 *
	 * @param string $code The receipt code.
	 * @param string $address The caller's address, for the throttler only. It
	 *                        is never stored against the report.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	public function openByCode(string $code, string $address = ''): ?array;

	/**
	 * Write a message on the thread.
	 *
	 * A reporter's own message is always visible to them; a handler's is
	 * visible only when they said so, because the default for a note on a
	 * whistleblowing report has to be internal.
	 *
	 * @param string $reportId The report.
	 * @param string $author `reporter` or `handler`.
	 * @param string $body The message.
	 * @param bool $visibleToReporter Whether the reporter may read it.
	 * @param string $authorName The handler's name, when a handler wrote it.
	 *
	 * @return bool True when the message landed.
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- stored field, not a mode
	 * switch: it becomes the message's own visibility, and the one branch on it
	 * forces true for a reporter, whose messages are never internal.
	 */
	public function write(string $reportId, string $author, string $body, bool $visibleToReporter = false, string $authorName = ''): bool;
}//end interface
