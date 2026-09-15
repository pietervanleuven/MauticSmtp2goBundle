<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSmtp2goBundle\Mailer\Factory;

use MauticPlugin\MauticSmtp2goBundle\MauticSmtp2goBundle;
use MauticPlugin\MauticSmtp2goBundle\Mailer\Transport\Smtp2goApiTransport;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Registers the smtp2go+api DSN scheme with Symfony Mailer.
 *
 * The service is autoconfigured via the `mailer.transport_factory` tag because
 * AbstractTransportFactory is tagged by Symfony's Mailer DI extension.
 */
final class Smtp2goTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) {
            throw new UnsupportedSchemeException($dsn, 'smtp2go', $this->getSupportedSchemes());
        }

        // The API key may live in either DSN segment: smtp2go+api://KEY@default
        // or user:KEY@default (Mautic's config UI stores it as the password).
        // Dsn::getPassword() is used directly because the parent helper throws
        // when the password segment is absent instead of returning null.
        $apiKey = $dsn->getPassword() ?: $this->getUser($dsn);
        $host   = 'default' === $dsn->getHost() ? null : $dsn->getHost();
        $port   = $dsn->getPort();

        $transport = new Smtp2goApiTransport(
            $apiKey,
            $this->client,
            $this->dispatcher,
            $this->logger
        );

        if ($host) {
            $transport->setHost($host);
        }

        if (null !== $port) {
            $transport->setPort($port);
        }

        return $transport;
    }

    protected function getSupportedSchemes(): array
    {
        return [MauticSmtp2goBundle::SCHEME];
    }
}
