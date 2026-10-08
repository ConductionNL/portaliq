# Design: portal-scope-list-membership

## Architecture Overview

Portaliq scopes every direct portal read and write by one field on the row:
`row[scopeField]` against the subject's scoping value (the `subjectRef`, or a
server-resolved `scopeClaim`). Two methods hold that check today, and both cast
the stored value to a string:

| Where | Used by |
|---|---|
| `PortalObjectReader::verifyScope` | list read, single-object read, the portalAccount lookup behind `scopeClaim` |
| `PortalObjectWriter::fetchOwnedObject` | verified update: contribution update, mark-read, status transitions, the scoped file upload (PR 745) |

A list casts to `"Array"`, so a list scope field never matches. The change puts
one match rule in a trait both classes use:

```
PortalScopeMatch (trait)
  scopeMatches(stored, scopeValue): bool
    scopeValue ''            -> false
    string|int               -> (string)stored === scopeValue
    list                     -> any string|int element === scopeValue
    anything else            -> false   (null, absent, [], associative array, nested list, float, bool, object)

PortalObjectReader::verifyScope      -> scopeMatches(row[scopeField], subjectRef)
PortalObjectWriter::fetchOwnedObject -> scopeMatches(row[scopeField], subjectRef)
PortalObjectWriter::updateObject     -> re-stamp: stored list if it is a list, else subjectRef
PortalObjectWriter::createObject     -> stamp: [subjectRef] if the schema says type array, else subjectRef
```

The query side needs no change. `scopedFilters` still sends
`filters[scopeField] = scopeValue`, and OpenRegister's `MagicSearchHandler`
already turns a filter on an `array` property into a containment test
(`applyJsonArrayFilter`, `COALESCE(col, '[]')::jsonb @> '["value"]'`). The
query returns the right rows; the per-row rule stays the boundary.

## Nextcloud Integration

- Controllers: none changed.
- Services: `PortalObjectReader`, `PortalObjectWriter` (changed); `PortalScopeMatch` (new trait); `PortalSchemaReader` (reused, optional constructor dependency of the writer, resolved by the DI container).
- Mappers/Entities: OpenRegister `SchemaMapper::findBySlug` through `PortalSchemaReader`, read only.
- Events/Hooks: none.

## Security Considerations

This is a change to the ownership boundary itself, so every decision leans to
fail closed (ADR-005).

- **Strict membership only.** `in_array` with loose comparison would let
  `0 == "abc"` style coercions through; the rule normalises each element to a
  string only when it is a string or an integer, and compares with `===`.
- **No empty wildcard.** An empty scoping value never matches. Before this
  change an empty value matched rows whose scope field was absent, because
  `(string)(null ?? '') === ''`. No caller passes an empty value on purpose
  (the controllers, `PortalCaseListReader` and the intake resolver guard it),
  so closing this is a hardening with no behaviour loss.
- **An empty list is nobody's.** `[]` never matches, so a row whose list was
  cleared drops out of every portal.
- **An associative array is not a list.** An OpenRegister object reference
  such as `{"value": "<uuid>"}` fails closed rather than matching on a value.
- **The update cannot edit membership.** A verified list is re-stamped with the
  stored list itself after the merge, so a patch body that names the scope
  field is ignored and the other members keep their access.
- **The create cannot widen.** The stamp is always the subject's own ref,
  either as a string or as a one-element list; the client value is overwritten.
  The schema lookup only chooses the shape, so a wrong lookup at worst makes
  OpenRegister reject the write.
- **The tenant check is unchanged** and still runs after a scope match.

## File Structure

```
lib/
  Service/
    PortalScopeMatch.php      (new trait: the one scope rule)
    PortalObjectReader.php    (verifyScope uses the trait)
    PortalObjectWriter.php    (fetchOwnedObject uses the trait; update re-stamp keeps a list; create stamps a list for an array field)
tests/
  Unit/Service/
    PortalObjectReaderTest.php  (list membership matrix)
    PortalObjectWriterTest.php  (list update and create stamp matrix)
```

## Decisions

### A trait, not a static helper or a new service

The rule is a pure function that both classes need. A static helper would trip
PHPMD `StaticAccess`, which the fleet ruleset only exempts for `FleetAppId`. A
new injected service would add a constructor argument to the reader and the
writer, and three controllers build the writer by hand with named arguments.
A trait keeps one definition and changes no constructor for the match.

### The create stamp reads the schema

Alternatives: a new manifest key (`scopeFieldType: list`), or guessing from the
client body. A manifest key would need every leaf app to declare it and is
redundant with the schema, which already types the field. The client body is
never trusted. The writer therefore takes an optional `PortalSchemaReader`,
the same pattern `ActionConfigNormaliser` and `PortalManifestNormaliser`
already use, and falls back to the single value when it is absent or the
schema cannot be read. The three controllers that build the writer by hand use
`scopeField: ''` and never reach the stamp.

### The update re-stamps the stored list

Alternatives: stamp `[subjectRef]`, or append the subject to the client list.
Both change who can see the row. Re-stamping the stored list keeps membership
exactly as the domain app set it; portaliq never edits a membership list.

## Risks / Trade-offs

- [A future leaf app scopes by a list of objects, such as `[{"id": ...}]`] → it fails closed (reads empty) until the contract grows a path syntax; that is the safe direction.
- [`PortalSchemaReader` looks up by slug across registers] → the only effect is the stamp's shape, and a wrong shape fails the write in OpenRegister.
- [The empty-value hardening changes a corner of `verifyScope`] → no caller relies on it; the full PHPUnit suite runs before push.

## Migration Plan

None. No schema, register or data change. Deploy with the next release; revert
the PR to roll back.

## Seed Data

Not applicable: the change adds or modifies no schema. The learniq seed rows
that carry `learnerRefs` and `guardianRefs` are learniq's.

## Trade-offs

Matching a list element on the direct path mirrors what the reverse `via` join
already does in `rowInTargetSet`, so the contract now reads the same on both
paths. The cost is a small widening of what counts as "owned", bounded by
strict membership of a server-resolved value.
