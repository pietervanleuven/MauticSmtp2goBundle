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

The API key is accepted in either the user or the password position of the
DSN (`smtp2go+api://YOUR_API_KEY@default` and
`smtp2go+api://user:YOUR_API_KEY@default` both work), so it can also be
entered in the password field of Mautic's DSN form.

The literal host `default` is a Symfony Mailer convention meaning
*"use the factory's default endpoint"* (`api.smtp2go.com`). You may override
the host/port in the DSN if you need to target a different SMTP2GO endpoint.

## Features

- `smtp2go+api` DSN scheme registered with Symfony Mailer.
- API transport hitting `POST /v3/email/send` with the
  `X-Smtp2go-Api-Key` header.
- Translation of Symfony `Email` messages to the SMTP2GO JSON schema:
  `to`/`cc`/`bcc`, text + HTML bodies, attachments (base64 `fileblob`),
  inline images (`inlines`, referenced from the HTML as
  `cid:<content-id>`), reply-to, and custom headers such as
  `List-Unsubscribe`.
- Tokenized batch sending (`TokenTransportInterface`): Mautic renders a
  segment email once and queues up to 100 recipients per message; the
  transport then substitutes each contact's tokens (subject, bodies, and
  text headers) and issues one API call per recipient.
- Error handling that surfaces non-2xx responses and per-recipient failures
  from SMTP2GO as `HttpTransportException`.

## Batch sending behavior

SMTP2GO's API has no per-recipient template substitution, so a batch of N
recipients still results in N API requests — the win is that Mautic renders
the email once per batch instead of once per contact. If some recipients in
a batch fail, the remaining ones are still attempted; the transport then
throws an exception listing the failed addresses, which Mautic records as
failures for that batch.

## Limitations

- No webhook/callback handling for bounces, spam complaints, or
  unsubscribes, so Mautic will not learn about bounces on its own. That
  would be a follow-up.
- No custom configuration UI; relies on the standard Mautic DSN field.

## References

- [SMTP2GO API docs](https://developers.smtp2go.com/docs/send-an-email)
- [SMTP2GO official PHP SDK](https://github.com/smtp2go-oss/smtp2go-php)
  (reference for the `inlines`/`attachments` schema)
- [Mautic custom mailer transport discussion](https://forum.mautic.org/t/register-custom-mailer-transport-in-mautic-5/31670)
