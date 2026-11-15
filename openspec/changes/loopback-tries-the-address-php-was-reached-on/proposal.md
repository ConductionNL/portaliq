# Proposal: loopback-tries-the-address-php-was-reached-on

## Why

On the Conduction platform the PHP-FPM pod has nothing on port 80: the nginx sidecar listens on 8080. When the absolute URL did not answer from inside the server, the one retry on `http://127.0.0.1` failed too ("Failed to connect to 127.0.0.1 port 80"). `/portal/api/tasks` answered 502 and the Woo request path failed until an administrator set `internal_base_url` to `http://127.0.0.1:8080`. Seen in the Zuiddrecht go-live rehearsal on 2026-10-15.

## What changes

- After a connect-phase failure on the absolute URL, `InstanceLoopback` tries, in order and each once:
  1. the address and port the web server took the current request on, as PHP sees them (`SERVER_ADDR`, `SERVER_PORT`);
  2. `127.0.0.1` on that port;
  3. the origin of `overwrite.cli.url`;
  4. `http://127.0.0.1`.
  The absolute URL's own origin and duplicates are left out.
- It moves to the next address only after a connect-phase failure, so a request that reached a server is never sent twice.
- The address that answered is kept for the rest of the request. The info log names it; the warning names every address that failed.
- `internal_base_url` stays the explicit override and still wins over everything.
- The operations doc "Calls to this server" describes the order and the platform setting.

## Not changed

- The headers each call sends, and the original `Host` header on every retry.
- Nothing is remembered across requests.
