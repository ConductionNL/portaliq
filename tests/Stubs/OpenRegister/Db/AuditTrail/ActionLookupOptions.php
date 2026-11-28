<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\OpenRegister\Db\AuditTrail;

/**
 * Test stub: OpenRegister's audit-trail action lookup options, as on main
 * (28 Nov 2026), loaded only when OpenRegister itself is absent.
 */
final class ActionLookupOptions {
	/**
	 * Constructor.
	 *
	 * @param array<int, string> $actions Actions to return; every action when empty.
	 * @param int                $limit   Maximum rows.
	 */
	public function __construct(
		public readonly array $actions = [],
		public readonly int $limit = 50,
	) {
	}//end __construct()
}//end class
