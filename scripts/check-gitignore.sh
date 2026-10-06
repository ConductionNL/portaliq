#!/usr/bin/env bash
#
# check-gitignore.sh — fail when a TRACKED file matches an ignore rule.
#
# Why this exists (#66): `.gitignore` carries substring globs such as
# `**/*Analysis*`, `**/*references*` and `**/*encoding*`. `**/*references*`
# matches "Preferences", so it silently covered
# lib/Controller/PreferencesController.php. A tracked file stays tracked, so
# nothing complains day to day — but any tree that is reconstructed from the
# working copy (a re-add, a `git archive`-and-re-add, a fresh `git add -A`)
# drops it, and the quality gates then measure a different set of files than
# the one in the commit. openbuild measured exactly that: identical bytes, two
# gate verdicts. The pattern itself looks deliberate, so review never catches
# it. The negations in .gitignore fix today's matches; this script stops the
# next one from landing.
#
# The check is `git ls-files -ci --exclude-standard`: every tracked file that
# the ignore rules (.gitignore at every level, .git/info/exclude, core.excludesFile)
# would exclude. It must be empty.
#
# POSITIVE CONTROL. An empty result is also what a broken check returns — no
# ignore rules loaded, wrong directory, nothing tracked. So before trusting
# "no matches", the script proves its input is real:
#
#   * the index is non-empty (it is looking at this repository);
#   * a synthetic path built to hit one of the substring globs IS reported as
#     ignored by `git check-ignore --no-index` (the rules are loaded and the
#     very class of pattern that caused #66 is live).
#
# A gate whose absence looks exactly like its success is worse than no gate, so
# every way this can fail to check something exits non-zero with a named reason.
#
# Exit codes:
#   0 — no tracked file matches an ignore rule
#   1 — at least one does, or the check could not be trusted to have run
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_ROOT}"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	echo "✗ gitignore: ${REPO_ROOT} is not a git work tree — NOTHING was checked. Refusing to report a pass." >&2
	exit 1
fi

# Positive control 1: there is something to check.
tracked_count="$(git ls-files | wc -l | tr -d ' ')"
if [ "${tracked_count}" -eq 0 ]; then
	echo "✗ gitignore: the index lists no tracked files — NOTHING was checked. Refusing to report a pass." >&2
	exit 1
fi

# Positive control 2: the ignore rules are loaded, and the substring-glob
# class that caused #66 actually matches. The path need not exist
# (`--no-index` evaluates the rules alone). If someone anchors or removes
# `**/*Analysis*`, update this control to another live junk rule — do not
# delete it.
CONTROL_PATH="lib/Controller/gitignore-control-Analysis.txt"
if ! git check-ignore -q --no-index "${CONTROL_PATH}"; then
	echo "✗ gitignore: positive control failed — '${CONTROL_PATH}' should match an ignore rule (e.g. '**/*Analysis*') but does not. Either the ignore rules were not loaded or that rule changed; the check cannot be trusted. Refusing to report a pass." >&2
	exit 1
fi

matches="$(git ls-files -ci --exclude-standard)"
if [ -n "${matches}" ]; then
	echo "✗ gitignore: these TRACKED files match an ignore rule. They will silently vanish from any reconstructed tree (re-add, archive, fresh clone of a working copy) and from the quality gates with them:" >&2
	while IFS= read -r f; do
		rule="$(git check-ignore -v --no-index -- "${f}" 2>/dev/null || true)"
		echo "  ${f}    <- ${rule%%	*}" >&2
		if [ -n "${GITHUB_ACTIONS:-}" ]; then
			echo "::error file=${f}::tracked file matches ignore rule ${rule%%	*}"
		fi
	done <<< "${matches}"
	echo "Fix the RULE, not the file: anchor the glob to the directory it was meant for, or add a negation next to it (see the comment above the substring globs in .gitignore)." >&2
	exit 1
fi

echo "✓ gitignore: none of ${tracked_count} tracked files match an ignore rule (positive control '${CONTROL_PATH}' matched)."
