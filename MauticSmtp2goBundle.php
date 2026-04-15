<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSmtp2goBundle;

use Mautic\IntegrationsBundle\Bundle\AbstractPluginBundle;

class MauticSmtp2goBundle extends AbstractPluginBundle
{
    /**
     * DSN scheme consumed by the Symfony Mailer transport factory.
     * Users configure MAUTIC_MAILER_DSN=smtp2go+api://APIKEY@default
     */
    public const SCHEME = 'smtp2go+api';

    /**
     * Host segment is meaningless for the SMTP2GO API transport but Symfony
     * requires one, so we document the convention here.
     */
    public const HOST = 'api.smtp2go.com';
}
