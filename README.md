![CI](https://github.com/b13/stderr-logging/actions/workflows/ci.yml/badge.svg)

# EXT:stderr_logging - TYPO3 logs on stderr, for Kubernetes

## Introduction

TYPO3 writes its logs to files in `var/log/`. In a container nobody reads
these files: Kubernetes, Docker and every log collector built on them
(Fluent Bit, Vector, Promtail/Loki, Datadog, Elastic Agent) pick up what a
process writes to **stderr**, and the files only fill the pod's disk until
it is gone.

This extension is built for exactly these setups. Install it, and
everything TYPO3 would write to `var/log/` goes to stderr instead, one JSON
object per line.

## Installation

```bash
composer require b13/stderr-logging
```

That's it. The extension is activated by default: as soon as it is
installed, TYPO3 logs to stderr. You do not need to touch
`$GLOBALS['TYPO3_CONF_VARS']['LOG']`, the extension configuration or the
site configuration.

## What it does

| Before                                                    | With this extension                                    |
|-----------------------------------------------------------|--------------------------------------------------------|
| `var/log/typo3_<hash>.log`, warnings and up               | stderr, warnings and up                                |
| `var/log/typo3_deprecations_<hash>.log` (when enabled)    | stderr (when enabled)                                  |
| `FileWriter` / `RotatingFileWriter` of any extension      | stderr, on the same level                              |
| `DatabaseWriter`, `SyslogWriter`, custom writers           | unchanged                                              |
| Log levels per component in `$GLOBALS['TYPO3_CONF_VARS']['LOG']` | unchanged                                       |

Every file writer anywhere in `$GLOBALS['TYPO3_CONF_VARS']['LOG']` is
replaced, not only the default one: TYPO3 uses the most specific
configuration for a component, so a single `FileWriter` left in place would
keep writing files for that component.

Loggers that TYPO3 creates during bootstrap, before any extension is
loaded, are switched over as well (cache backends, package manager, error
and exception handler).

## Output

One line per log record:

```json
{"time":"2026-11-26T14:03:11.482+01:00","level":"warning","channel":"TYPO3.CMS.Backend.Security.PasswordAuthenticator","message":"Login-attempt from 192.0.2.10, username \"admin\", password not accepted!","request_id":"6745c9cf7b1e2","context":{"ip":"192.0.2.10","username":"admin"}}
```

| Field             | Content                                                                     |
|-------------------|-----------------------------------------------------------------------------|
| `time`            | RFC 3339 with milliseconds, in the PHP time zone                            |
| `level`           | PSR-3 level: `debug` … `emergency`                                          |
| `channel`         | the logger name, i.e. the class or `#[Channel]` that logged                 |
| `message`         | the message, `{placeholders}` replaced from the context                     |
| `request_id`      | TYPO3's request id, the same for all records of one request                 |
| `http_request_id` | the `X-Request-Id` header of the request, when your ingress or proxy sets one |
| `context`         | the log context, see below                                                  |
| `exception`       | class, message, code, file, the first 20 frames of the trace, `previous`    |

Objects in the context are logged as `[object ClassName]`, not with their
properties, so no request, user or service object spills its contents into
the log. `\Stringable`, `\JsonSerializable`, dates and enums are logged as
their value.

## Configuration

It's activated by default, there is nothing you have to configure. The
log levels stay the ones TYPO3 and your extensions already define in
`$GLOBALS['TYPO3_CONF_VARS']['LOG']` (TYPO3's default: `warning` and up).

Everything below is optional.

### Environment variables

| Variable                      | Example | Effect                                                    |
|-------------------------------|---------|-----------------------------------------------------------|
| `TYPO3_STDERR_LOGGING`        | `0`     | switches the extension off, TYPO3 writes files again      |
| `TYPO3_STDERR_LOGGING_LEVEL`  | `debug` | one minimum level for all output, instead of TYPO3's      |

`TYPO3_STDERR_LOGGING` accepts `0`, `false`, `off` and `no` to switch the
extension off; any other value, or no value at all, keeps it active.
`TYPO3_STDERR_LOGGING=0` is handy for local development, where you might
prefer the files. `TYPO3_STDERR_LOGGING_LEVEL=debug` on a single pod helps
when hunting a problem.

In Kubernetes, set them in the container spec of your deployment:

```yaml
env:
  - name: TYPO3_STDERR_LOGGING_LEVEL
    value: "notice"
```

### Cover the install tool and early bootstrap

The install tool does not load extensions and keeps writing to
`var/log/`. To cover it too, add this line to
`config/system/additional.php`, which the install tool reads:

```php
\B13\StderrLogging\StderrLogging::configure();
```

TYPO3 reads `additional.php` before it creates the first logger, so with
this line no file writer is created at all, not even during bootstrap. The
extension itself keeps working as before; using both does no harm.

### Use the writer in your own log configuration

To send a component to stderr with its own level, use the writer like any
other TYPO3 log writer:

```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['B13']['MyExtension']['writerConfiguration'] = [
    \Psr\Log\LogLevel::INFO => [
        \B13\StderrLogging\Log\Writer\StderrWriter::class => [],
    ],
];
```

The writer takes one option, `stream`, e.g. `php://stdout` instead of the
default `php://stderr`.

## Make sure stderr reaches the container log

The extension writes to `php://stderr`. Where that ends up depends on how
PHP runs:

**PHP-FPM** hands the stderr of its workers to its own log only when told
to. The official `php:*-fpm` images set this already, check it for your own
images:

```ini
; php-fpm.conf or pool configuration
error_log = /proc/self/fd/2
catch_workers_output = yes
decorate_workers_output = no
log_limit = 16384
```

- `decorate_workers_output = no` keeps FPM from wrapping every line in
  `WARNING: [pool www] child 12 said into stderr: "…"`, which breaks JSON
  parsing.
- `log_limit` is the longest line FPM passes on in one piece (default 1024
  bytes). Longer lines are split, and log records with an exception easily
  exceed 1024 bytes.

**FrankenPHP, RoadRunner and the CLI** (`vendor/bin/typo3`, scheduler,
message consumers) write stderr directly: nothing to do.

**Apache with mod_php** writes stderr to its `ErrorLog`: point that to
`/proc/self/fd/2`.

PHP's own errors (fatal errors, errors before TYPO3 is booted) do not pass
TYPO3's logging. Send them to stderr in `php.ini`:

```ini
log_errors = On
error_log = /dev/stderr
```

## Good to know

- **Files created during bootstrap.** A `FileWriter` opens its file when it
  is created, so `var/log/typo3_<hash>.log` may still appear, empty, from
  loggers created before the extension was loaded. Nothing is written to
  it. The `additional.php` line (see Configuration) prevents that.
- **On the command line** log records appear in the terminal, as they go to
  stderr. Use `TYPO3_STDERR_LOGGING=0` for a run where you do not want that.
- **Configuration you add later** in `ext_localconf.php` of an extension
  loaded after this one, with a `FileWriter`, writes files again. Use
  `\B13\StderrLogging\Log\Writer\StderrWriter::class` in your own log
  configuration instead.

## Compatibility

TYPO3 v13.4, v14.3 and v15, PHP 8.2 or newer (PHP 8.5 for TYPO3 v15).

## Open Issues

If you find an issue, feel free to create an [issue on GitHub](https://github.com/b13/stderr-logging/issues) or a [pull request](https://github.com/b13/stderr-logging/pulls).

### Credits

This extension was created by [Benni Mack](https://github.com/bmack) in 2026 for [b13 GmbH](https://b13.com).

[Find more TYPO3 extensions we have developed](https://b13.com/useful-typo3-extensions-from-b13-to-you) that help us deliver value in client projects. As part of the way we work, we focus on testing and best practices to ensure long-term performance, reliability, and results in all our code.
