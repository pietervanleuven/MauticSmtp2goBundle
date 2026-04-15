# Mautic SMTP2GO Bundle

A Mautic 5 plugin that adds [SMTP2GO](https://www.smtp2go.com/) as a native
Symfony Mailer transport, so emails are delivered through the SMTP2GO HTTP API
rather than SMTP.

## Requirements

- Mautic `^5.0`
- PHP `>=8.1`
- An SMTP2GO account and API key

## Installation

Install via Composer from your Mautic root:

```bash
composer require pietervanleuven/mautic-smtp2go-bundle
php bin/console mautic:plugins:reload
php bin/console cache:clear
```

Or drop the bundle folder into `plugins/MauticSmtp2goBundle/` and run the same
two console commands.

## Configuration

This plugin registers the DSN scheme `smtp2go+api`. Configure it in
**Settings → Configuration → Email Settings** by choosing *"Other"* as the
transport and entering a DSN:

```
smtp2go+api://YOUR_API_KEY@default
```

Or set it via environment variable:

```
MAUTIC_MAILER_DSN=smtp2go+api://YOUR_API_KEY@default
```

The literal host `default` is a Symfony Mailer convention meaning
*"use the factory's default endpoint"* (`api.smtp2go.com`). You may override
the host/port in the DSN if you need to target a different SMTP2GO endpoint.

## What's included

- `smtp2go+api` DSN scheme registered with Symfony Mailer.
- API transport hitting `POST /v3/email/send` with the
  `X-Smtp2go-Api-Key` header.
- Translation of Symfony `Email` messages to the SMTP2GO JSON schema,
  including `to`/`cc`/`bcc`, text + HTML bodies, attachments (as base64
  `fileblob`), inline attachments (`inlines`), reply-to, and custom headers.
- Error handling that surfaces non-2xx responses and per-recipient failures
  from SMTP2GO as `HttpTransportException`.

## Limitations of this basic version

- No batch/tokenization support (`TokenTransportInterface`) — Mautic will
  send one email per API request.
- No webhook/callback handling for bounces, spam complaints, or
  unsubscriptions. That would be a follow-up.
- No custom configuration UI; relies on the standard Mautic DSN field.

## References

- [SMTP2GO API docs](https://developers.smtp2go.com/docs/send-an-email)
- [Mautic custom mailer transport discussion](https://forum.mautic.org/t/register-custom-mailer-transport-in-mautic-5/31670)
