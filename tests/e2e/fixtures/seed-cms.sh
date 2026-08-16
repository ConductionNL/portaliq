#!/usr/bin/env bash
# Seed the CMS fixtures the phase-two e2e suite and the Docusaurus headless
# proof both read: two portals (so cross-site isolation is testable with a
# real second site, not a hypothetical one), a menu, markdown and grid pages,
# and glossary terms.
#
# Idempotent by slug/route: re-running replaces nothing and creates nothing
# twice, so a partially-seeded instance converges instead of accumulating.
#
# Usage: NC_URL=http://localhost:8321 ./seed-cms.sh
#
# SPDX-License-Identifier: EUPL-1.2
set -euo pipefail

NC_URL="${NC_URL:-http://localhost:8321}"
AUTH="${NC_AUTH:-admin:admin}"
API="${NC_URL}/index.php/apps/openregister/api/objects/portaliq"

req() { curl -sS -u "$AUTH" -H 'OCS-APIRequest: true' -H 'Content-Type: application/json' "$@"; }

# Return the id of an existing object matching a property, or empty.
find_by() { # schema property value
	# `_limit`, not `limit`: OpenRegister treats an un-prefixed control param as
	# a PROPERTY FILTER, so `?limit=200` silently matches nothing and every
	# lookup here returns empty — which this upsert would read as "does not
	# exist yet" and duplicate the object on every run. The API says so in its
	# own response hint; it is easy to miss because the request still 200s.
	req "${API}/$1?_limit=500" | python3 -c "
import json,sys
prop,val=sys.argv[1],sys.argv[2]
d=json.load(sys.stdin)
for o in (d.get('results') or []):
    if o.get(prop)==val:
        print(o.get('@self',{}).get('id') or o.get('id')); break
" "$2" "$3"
}

# True when an existing object carries the `portal` scope the reader filters on.
scoped_to_portal() { # schema id
	req "${API}/$1/$2" | python3 -c "
import json,sys
try:
    o=json.load(sys.stdin)
except Exception:
    sys.exit(1)
sys.exit(0 if o.get('portal') else 1)
"
}

upsert() { # schema property value json
	local existing
	existing="$(find_by "$1" "$2" "$3")"
	if [ -n "$existing" ]; then
		# Found by key — but "found" is not "usable". This seeder only ever
		# CREATES; it never updates. So an object left behind by an older
		# schema satisfies the key lookup and is skipped, and the seed reports
		# success while every content read returns empty.
		#
		# That is not hypothetical. The `website` -> `portal` rename orphaned
		# all eleven fixtures: `CmsReader` filters on `portal`, the objects
		# still carried `website`, and this script printed "Seed complete."
		# over a portal serving nothing.
		#
		# So assert the scoping property the reader actually filters on. A
		# fixture that cannot be read is a failure, not a skip.
		if [ "$1" != portal ] && ! scoped_to_portal "$1" "$existing"; then
			echo "seed: $1/$existing matches $2=$3 but carries no 'portal' — stale fixture from an older schema; delete it or migrate it before re-seeding" >&2
			return 1
		fi
		echo "$existing"
		return
	fi
	req -X POST "${API}/$1" -d "$4" | python3 -c "
import json,sys
d=json.load(sys.stdin)
i=d.get('@self',{}).get('id') or d.get('id')
if not i:
    sys.stderr.write('seed failed: '+json.dumps(d)[:300]+'\n'); sys.exit(1)
print(i)
"
}

echo "==> portals"
SITE=$(upsert portal slug open-tilburg '{
  "title":"Open Tilburg","slug":"open-tilburg","status":"published",
  "domains":[{"hostname":"localhost","verified":true}],
  "theme":"vng","locales":["nl","en"],
  "authentication":{"modes":["public"]},
  "frameAncestors":[],"organisation":"dev-org"
}')
echo "    open-tilburg = $SITE"

# A second site exists so "content does not leak across portals" can be
# tested against a real other site. A single-site fixture makes that scenario
# unfalsifiable — it would pass even if scoping were not implemented at all.
#
# It carries TWO domains on purpose: one verified, one not. Testing only the
# unverified case would leave a permanently-refusing verifier
# indistinguishable from a working one; testing only the verified case would
# leave the refusal untested. Both directions live in the fixture.
SITE2=$(upsert portal slug open-venray '{
  "title":"Gemeente Venray","slug":"open-venray","status":"published",
  "domains":[
    {"hostname":"venray.localhost","verified":true},
    {"hostname":"unverified.localhost","verified":false}
  ],
  "theme":"venray","locales":["nl"],
  "authentication":{"modes":["public"]},
  "frameAncestors":[],"organisation":"dev-org"
}')
echo "    open-venray  = $SITE2 (venray.localhost verified, unverified.localhost not)"

echo "==> menu"
upsert menu title Hoofdmenu '{
  "title":"Hoofdmenu","portal":"open-tilburg","position":0,
  "items":[
    {"order":0,"name":"Home","link":"/","icon":"Home"},
    {"order":1,"name":"Over ons","link":"/over-ons","icon":"Information",
      "items":[{"order":0,"name":"Contact","link":"/contact","icon":"Email"}]},
    {"order":2,"name":"Begrippen","link":"/begrippen","icon":"BookAlphabet"}
  ]
}' >/dev/null
echo "    Hoofdmenu (2 levels)"

echo "==> pages"
upsert page route / '{
  "title":"Welkom","route":"/","portal":"open-tilburg","status":"published","locale":"nl",
  "summary":"De startpagina van Open Tilburg.",
  "body":{"type":"grid","widgets":[
    {"id":"intro","widgetKey":"markdown","slot":"body","gridX":0,"gridY":0,"gridWidth":12,"gridHeight":4,
     "props":{"markdown":"# Welkom bij Open Tilburg\n\nEen plek voor alle publicaties van de gemeente."}},
    {"id":"side","widgetKey":"markdown","slot":"body","gridX":0,"gridY":4,"gridWidth":6,"gridHeight":3,
     "props":{"markdown":"## Actueel\n\nHet laatste nieuws."}},
    {"id":"help","widgetKey":"markdown","slot":"body","gridX":6,"gridY":4,"gridWidth":6,"gridHeight":3,
     "props":{"markdown":"## Hulp nodig?\n\nNeem contact met ons op."}}
  ]}
}' >/dev/null
echo "    / (grid, 3 widgets)"

upsert page route /over-ons '{
  "title":"Over ons","route":"/over-ons","portal":"open-tilburg","status":"published","locale":"nl",
  "summary":"Wie wij zijn en waar wij voor staan.",
  "body":{"type":"markdown","markdown":"## Over ons\n\nWij publiceren overheidsinformatie op grond van de **Wet open overheid**.\n\n- Transparant\n- Toegankelijk\n- Actueel\n\n### Een Woo-verzoek indienen\n\nDat kan via het contactformulier.\n\n```text\nvoorbeeldcode blijft intact\n```\n\n| Kolom | Waarde |\n| --- | --- |\n| Een | 1 |\n| Twee | 2 |\n"}
}' >/dev/null
echo "    /over-ons (markdown, incl. code fence + table)"

upsert page route /contact '{
  "title":"Contact","route":"/contact","portal":"open-tilburg","status":"published","locale":"nl",
  "summary":"Hoe u ons bereikt.",
  "body":{"type":"markdown","markdown":"## Contact\n\nBel 14 013 of mail info@tilburg.nl.\n"}
}' >/dev/null
echo "    /contact (markdown)"

# A draft page proves the published filter does something. Without it, the
# "unpublished pages are not served" scenario passes vacuously.
upsert page route /concept '{
  "title":"Nog niet klaar","route":"/concept","portal":"open-tilburg","status":"draft","locale":"nl",
  "summary":"Deze pagina hoort niet zichtbaar te zijn.",
  "body":{"type":"markdown","markdown":"Dit is een concept."}
}' >/dev/null
echo "    /concept (DRAFT — must never be served)"

# A page on the OTHER site at the SAME route, so cross-site isolation is a
# real comparison rather than an absence.
# Looked up by TITLE, not route: its route is `/over-ons`, deliberately the
# same as the Tilburg page. A route-keyed lookup here would either match the
# wrong site's page or (as it did) match nothing and duplicate on every run.
upsert page title 'Over Venray' '{
  "title":"Over Venray","route":"/over-ons","portal":"open-venray","status":"published","locale":"nl",
  "summary":"Venray, niet Tilburg.",
  "body":{"type":"markdown","markdown":"## Over Venray\n\nDit is de Venray-site.\n"}
}' >/dev/null
echo "    /over-ons on open-venray (same route, other site)"

# The menu links to /begrippen, so a page has to exist there. Without it the
# link resolves to a 404 the visitor never sees (the glossary still renders)
# while the console carries an error on every visit — the shape of defect that
# survives for months because the page looks right.
# The glossary is a BLOCK an author places, not a section the renderer emits
# on a route it recognises. It used to be the latter: a hard-coded <section>
# with a literal <h2>Begrippenlijst</h2> that no portal could move, rename,
# translate or leave out. When that came out, nothing placed the block — so
# this page rendered its heading and intro over nothing while the terms sat
# unused behind the public contract. The page must ASK for the glossary.
upsert page title 'Begrippenlijst' '{
  "title":"Begrippenlijst","route":"/begrippen","portal":"open-tilburg","status":"published","locale":"nl",
  "summary":"Uitleg van veelgebruikte begrippen.",
  "body":{"type":"grid","widgets":[
    {"id":"intro","widgetKey":"markdown","slot":"body","gridX":0,"gridY":0,"gridWidth":12,"gridHeight":2,
     "props":{"markdown":"Hieronder staan de begrippen die op deze site worden gebruikt.\n"}},
    {"id":"terms","widgetKey":"glossary","slot":"body","gridX":0,"gridY":2,"gridWidth":12,"gridHeight":6,
     "props":{"synonymsLabel":"Ook bekend als:","sourceLabel":"Bron:",
              "emptyLabel":"Er zijn nog geen begrippen vastgelegd."}}
  ]}
}' >/dev/null
echo "    /begrippen (grid: intro + glossary block; the menu links here)"

# Hostile markdown. The page also carries ordinary prose on purpose: a
# sanitiser that threw the whole document away would pass a test that only
# checked "no script ran".
upsert page title 'Sanitisatieproef' '{
  "title":"Sanitisatieproef","route":"/xss-probe","portal":"open-tilburg","status":"published","locale":"nl",
  "summary":"Fixture voor sanitisatie.",
  "body":{"type":"markdown","markdown":"## Veilige tekst blijft staan\n\n<script>window.__pqXssRan = true;<\/script>\n\n[klik mij](javascript:window.__pqXssRan=true)\n\n<img src=x onerror=\"window.__pqXssRan=true\">\n\nEinde van de pagina.\n"}
}' >/dev/null
echo "    /xss-probe (hostile markdown + surviving prose)"

# A grid page mixing a public widget key with one that is not public, so
# "degrades instead of blanking" is observable rather than assumed.
upsert page title 'Widgetproef' '{
  "title":"Widgetproef","route":"/widget-probe","portal":"open-tilburg","status":"published","locale":"nl",
  "summary":"Fixture voor widget-gating.",
  "body":{"type":"grid","widgets":[
    {"id":"ok-one","widgetKey":"markdown","slot":"body","gridX":0,"gridY":0,"gridWidth":6,"gridHeight":2,
     "props":{"markdown":"Publieke widget een."}},
    {"id":"blocked","widgetKey":"files","slot":"body","gridX":6,"gridY":0,"gridWidth":6,"gridHeight":2,
     "props":{"folder":"/geheim"}},
    {"id":"ok-two","widgetKey":"markdown","slot":"body","gridX":0,"gridY":2,"gridWidth":12,"gridHeight":2,
     "props":{"markdown":"Publieke widget twee."}}
  ]}
}' >/dev/null
echo "    /widget-probe (1 non-public widget among 2 public)"

echo "==> glossary"
upsert glossaryTerm term Woo-verzoek '{
  "term":"Woo-verzoek","portal":"open-tilburg",
  "definition":"Een verzoek om openbaarmaking van overheidsinformatie.",
  "synonyms":["Wob-verzoek"],"source":"Wet open overheid, artikel 4.1"
}' >/dev/null
upsert glossaryTerm term Publicatie '{
  "term":"Publicatie","portal":"open-tilburg",
  "definition":"Een document dat de gemeente actief openbaar maakt.",
  "synonyms":[],"source":"Wet open overheid, artikel 3.3"
}' >/dev/null
echo "    2 terms"

echo
echo "Seed complete."
