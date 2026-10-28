# Document server converter (fileconverter_docserver)

A Moodle document converter that turns Word, Excel, PowerPoint and OpenDocument files into
PDF through the Conversion API of an **ONLYOFFICE compatible document server**. Its main use
is the Assignment activity: teachers can annotate office submissions with *Annotate PDF*
without students having to upload PDFs.

Maintained by **Moodle in Niedersachsen e. V.**
Based on [fileconverter_onlyoffice](https://github.com/learnweb/moodle-fileconverter_onlyoffice)
by Jan Dageförde, Learnweb, University of Münster.

## Requirements

- Moodle 5.0 or later (tested with 5.2)
- PHP 8.2, 8.3 or 8.4
- PostgreSQL, MariaDB or MySQL
- An ONLYOFFICE Docs server (Community Edition is enough) or another server that implements
  the ONLYOFFICE Conversion API, **with JWT enabled** (the default since ONLYOFFICE 7.2)
- Ghostscript configured in Moodle (*Site administration > Server > System paths*), as for
  every PDF annotation setup

## Supported formats

Source: `csv`, `doc`, `docx`, `htm`, `html`, `odp`, `ods`, `odt`, `ppt`, `pptx`, `rtf`,
`txt`, `xls`, `xlsx`. Target: `pdf`. Spreadsheets are scaled to page width.

## Installation

1. Copy the folder `docserver` to `files/converter/docserver` (Moodle 5.1 and later:
   `public/files/converter/docserver`).
2. Run the upgrade (`php admin/cli/upgrade.php`) or visit *Site administration > Notifications*.
3. Enable the converter under *Site administration > Plugins > Document converters >
   Manage document converters* and move it to the top if other converters are enabled.
4. Enable *Annotate PDF* in the assignment feedback settings.

## Configuration

*Site administration > Plugins > Document converters > Document server converter*

| Setting | Meaning |
|---|---|
| Document server URL | Base URL as Moodle reaches the server, e.g. `https://onlyoffice.example.org`. Results are only downloaded from this host. |
| Moodle URL for the document server | Only if the server reaches Moodle under a different address (containers). Empty = site URL. |
| JWT secret | The server's `services.CoAuthoring.secret` value. |
| Require signed downloads | Moodle hands out a file only if the request carries the server's JWT. Default: on. |
| Request timeout | Per HTTP request, default 30 s. |
| Maximum conversion duration | After this a conversion is marked as failed, default 30 min. |

After saving, open **Run connection test** (link at the top of the settings page). The test
converts a bundled Word document and checks both directions: Moodle → server and
server → Moodle.

### Network checklist

- **Moodle → document server:** if the server is on a private network or a non-standard
  port, adjust *Site administration > General > Security > HTTP security*
  (`curlsecurityblockedhosts`, `curlsecurityallowedport`).
- **Document server → Moodle:** the server downloads the file from
  `…/pluginfile.php/1/fileconverter_docserver/source/…`. If Moodle has a private IP address,
  ONLYOFFICE must be allowed to fetch from it
  (`services.CoAuthoring.request-filtering-agent.allowPrivateIPAddress`).
- **Authorization header:** with *Require signed downloads*, the web server must pass the
  `Authorization` header to PHP. nginx with PHP-FPM does this by default; for Apache with
  PHP-FPM or CGI add `CGIPassAuth On` (or
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`).

### Several Moodle sites, one document server

Supported. Every request key contains a hash of the site identifier, so sites cannot get
each other's cached results. Each site needs the same JWT secret as the server.

## How it works

1. Core asks the converter to convert a file and marks the conversion as *in progress*.
2. The converter sends an asynchronous conversion request (signed with JWT) containing a
   download URL. The URL carries the conversion id, an expiry time and an HMAC signature;
   the original file name is replaced by `document.<ext>`.
3. The document server downloads the file. Moodle checks the conversion state, the
   signature, the expiry and the server's JWT before sending it.
4. The scheduled task *Poll the document server for finished conversions* (every minute,
   idle when nothing is running) asks for the result, downloads the PDF from the
   configured host, checks it is a PDF and stores it.

## Security

- The download endpoint does not use a Moodle login (the document server cannot log in).
  Instead access requires: a conversion of this converter that is in progress, a valid
  HMAC-SHA256 signature over conversion id, file id, content hash and expiry (site-local
  secret created at installation), an unexpired URL and, with a JWT secret, a valid server
  JWT whose URL contains the same signature. Finished conversions can no longer be fetched.
- Results are only downloaded from the configured server host and must start with `%PDF-`.
- All HTTP traffic uses Moodle's `curl` class with timeouts, so proxy and curl security
  settings apply.
- Failures are stored in the conversion status message and logged as events
  (*Document conversion requested*, *Document conversion failed*).

## Privacy

The plugin stores no personal data. The content and type of files to convert are sent to
the configured document server, which keeps them only while converting. File names are not
sent. The privacy API declares this external location.

## Differences from fileconverter_onlyoffice

- Fixes an ineffective access check in the download endpoint (the content hash comparison
  never applied), replaced by signed, expiring URLs and JWT verification.
- JWT support for requests, responses and downloads.
- Failed conversions are marked as failed instead of being retried forever; maximum
  conversion duration.
- Result host and file type checks; no original file names sent to the server.
- Connection test page, Moodle 5.x support, PHPUnit tests.

## Development

```
vendor/bin/phpunit --testsuite fileconverter_docserver_testsuite
```

## License

GNU GPL v3 or later, see `LICENSE.md`.
