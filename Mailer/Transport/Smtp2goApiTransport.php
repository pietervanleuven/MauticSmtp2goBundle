<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSmtp2goBundle\Mailer\Transport;

use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Symfony Mailer API transport for SMTP2GO.
 *
 * Endpoint: POST https://api.smtp2go.com/v3/email/send
 * Auth:     X-Smtp2go-Api-Key header
 * Docs:     https://developers.smtp2go.com/docs/send-an-email
 */
final class Smtp2goApiTransport extends AbstractApiTransport implements TokenTransportInterface
{
    use TokenTransportTrait;

    private const HOST = 'api.smtp2go.com';
    private const ENDPOINT = '/v3/email/send';

    /**
     * Recipients Mautic may queue into one tokenized MauticMessage. SMTP2GO
     * has no per-recipient substitution API, so each recipient still costs
     * one HTTP request; this bounds memory and the blast radius of a batch
     * that fails halfway.
     */
    private const MAX_BATCH_LIMIT = 100;

    private const HEADERS_TO_BYPASS = [
        'from',
        'sender',
        'to',
        'cc',
        'bcc',
        'reply-to',
        'subject',
        'content-type',
        'mime-version',
        'date',
        'message-id',
    ];

    public function __construct(
        private readonly string $apiKey,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('smtp2go+api://%s', $this->getEndpoint());
    }

    public function getMaxBatchLimit(): int
    {
        return self::MAX_BATCH_LIMIT;
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $payload = $this->getPayload($email, $envelope);

        if ($email instanceof MauticMessage && [] !== $email->getMetadata()) {
            return $this->sendTokenizedBatch($sentMessage, $email->getMetadata(), $payload);
        }

        [$response, $emailId] = $this->sendPayload($payload);

        if (null !== $emailId) {
            $sentMessage->setMessageId($emailId);
        }

        return $response;
    }

    /**
     * Send one API request per recipient of a tokenized Mautic batch, with
     * that recipient's token values substituted into the rendered message.
     *
     * @param array<string, array<string, mixed>> $metadata recipient email => Mautic metadata (name, tokens, ...)
     */
    private function sendTokenizedBatch(SentMessage $sentMessage, array $metadata, array $payload): ResponseInterface
    {
        $response = null;
        $failures = [];

        foreach ($metadata as $recipientEmail => $contact) {
            $recipientPayload       = $payload;
            $recipientPayload['to'] = [$this->formatAddress(new Address($recipientEmail, (string) ($contact['name'] ?? '')))];

            if ($tokens = $contact['tokens'] ?? []) {
                $recipientPayload = $this->replaceTokens($recipientPayload, $tokens);
            }

            try {
                [$response, $emailId] = $this->sendPayload($recipientPayload);

                if (null !== $emailId) {
                    $sentMessage->setMessageId($emailId);
                }
            } catch (HttpTransportException $e) {
                $failures[$recipientEmail] = $e->getMessage();
                $response                  = $e->getResponse();
            }
        }

        if ([] !== $failures) {
            throw new HttpTransportException(
                sprintf('SMTP2GO failed for %d of %d recipient(s): %s', count($failures), count($metadata), json_encode($failures)),
                $response
            );
        }

        return $response;
    }

    /**
     * Substitute per-recipient Mautic tokens, mirroring the scope of
     * MailHelper::searchReplaceTokens(): subject, bodies, and text headers.
     *
     * @param array<string, string> $tokens token => replacement value
     */
    private function replaceTokens(array $payload, array $tokens): array
    {
        $search  = array_keys($tokens);
        $replace = array_values($tokens);

        foreach (['subject', 'text_body', 'html_body'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = str_ireplace($search, $replace, $payload[$field]);
            }
        }

        foreach ($payload['custom_headers'] ?? [] as $i => $header) {
            $payload['custom_headers'][$i]['value'] = str_ireplace($search, $replace, $header['value']);
        }

        return $payload;
    }

    /**
     * POST one payload to the SMTP2GO API and validate the response.
     *
     * @return array{0: ResponseInterface, 1: ?string} the response and the reported email_id
     */
    private function sendPayload(array $payload): array
    {
        $response = $this->client->request('POST', 'https://'.$this->getEndpoint(), [
            'headers' => [
                'Accept'              => 'application/json',
                'Content-Type'        => 'application/json',
                'X-Smtp2go-Api-Key'   => $this->apiKey,
            ],
            'json' => $payload,
        ]);

        try {
            $statusCode = $response->getStatusCode();
            $result     = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new HttpTransportException('Could not reach the SMTP2GO API: '.$e->getMessage(), $response, 0, $e);
        }

        if (200 !== $statusCode) {
            $error = $result['data']['error'] ?? $result['data']['error_code'] ?? $response->getContent(false);
            throw new HttpTransportException(
                sprintf('Unable to send an email via SMTP2GO (HTTP %d): %s', $statusCode, is_string($error) ? $error : json_encode($error)),
                $response
            );
        }

        $data = $result['data'] ?? [];
        if (isset($data['failed']) && (int) $data['failed'] > 0) {
            $failures = $data['failures'] ?? [];
            throw new HttpTransportException(
                sprintf('SMTP2GO rejected %d recipient(s): %s', (int) $data['failed'], json_encode($failures)),
                $response
            );
        }

        return [$response, isset($data['email_id']) ? (string) $data['email_id'] : null];
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: self::HOST).($this->port ? ':'.$this->port : '').self::ENDPOINT;
    }

    /**
     * Build the SMTP2GO JSON payload from a Symfony Mime Email.
     */
    private function getPayload(Email $email, Envelope $envelope): array
    {
        $email = MessageConverter::toEmail($email);

        $payload = [
            'sender'  => $this->formatAddress($envelope->getSender()),
            'to'      => $this->formatAddresses($email->getTo() ?: $envelope->getRecipients()),
            'subject' => (string) $email->getSubject(),
        ];

        if ($cc = $email->getCc()) {
            $payload['cc'] = $this->formatAddresses($cc);
        }

        if ($bcc = $email->getBcc()) {
            $payload['bcc'] = $this->formatAddresses($bcc);
        }

        if (null !== ($text = $email->getTextBody())) {
            $payload['text_body'] = (string) $text;
        }

        if (null !== ($html = $email->getHtmlBody())) {
            $payload['html_body'] = (string) $html;
        }

        if ($replyTo = $email->getReplyTo()) {
            // SMTP2GO supports reply-to via a custom header.
            $payload['custom_headers'][] = [
                'header' => 'Reply-To',
                'value'  => $this->formatAddresses($replyTo, true),
            ];
        }

        foreach ($email->getHeaders()->all() as $header) {
            $name = strtolower($header->getName());

            if (in_array($name, self::HEADERS_TO_BYPASS, true)) {
                continue;
            }

            if ($header instanceof TagHeader || $header instanceof MetadataHeader) {
                // SMTP2GO has no native tag/metadata API; expose as custom headers.
                $payload['custom_headers'][] = [
                    'header' => $header instanceof TagHeader ? 'X-Mautic-Tag' : 'X-Mautic-'.$header->getName(),
                    'value'  => $header->getBodyAsString(),
                ];
                continue;
            }

            if ($header instanceof UnstructuredHeader) {
                $payload['custom_headers'][] = [
                    'header' => $header->getName(),
                    'value'  => $header->getBodyAsString(),
                ];
            }
        }

        foreach ($email->getAttachments() as $attachment) {
            $headers  = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename') ?: 'file';
            $mime     = $attachment->getMediaType().'/'.$attachment->getMediaSubtype();

            $entry = [
                'filename' => $filename,
                'fileblob' => base64_encode($attachment->getBody()),
                'mimetype' => $mime,
            ];

            if ('inline' === $headers->getHeaderBody('Content-Disposition') && $attachment->hasContentId()) {
                // SMTP2GO has no cid field: the filename of an "inlines" entry
                // is the content id the HTML references as cid:<filename>.
                $entry['filename'] = $attachment->getContentId();
                $payload['inlines'][] = $entry;
            } else {
                $payload['attachments'][] = $entry;
            }
        }

        return $payload;
    }

    /**
     * @param Address[] $addresses
     * @return string|string[]
     */
    private function formatAddresses(array $addresses, bool $single = false): array|string
    {
        $formatted = array_map(fn (Address $a) => $this->formatAddress($a), $addresses);

        return $single ? implode(', ', $formatted) : $formatted;
    }

    private function formatAddress(Address $address): string
    {
        return '' !== $address->getName()
            ? sprintf('"%s" <%s>', addcslashes($address->getName(), '"'), $address->getAddress())
            : $address->getAddress();
    }
}
