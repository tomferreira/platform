<?php

namespace Oro\Bundle\EmailBundle\Mailer;

use Oro\Bundle\ImapBundle\Manager\ImapEmailGoogleOauth2Manager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\IntrospectableContainerInterface;

use Oro\Bundle\EmailBundle\Exception\NotSupportedException;
use Oro\Bundle\ImapBundle\Entity\UserEmailOrigin;
use Oro\Bundle\SecurityBundle\Encoder\Mcrypt;

/**
 * The goal of this class is to send an email directly, not using a mail spool
 * even when it is configured for a base mailer
 */
class DirectMailer extends \Swift_Mailer
{
    /**
     * @var \Swift_Mailer
     */
    protected $baseMailer;

    /**
     * @var \Swift_SmtpTransport
     */
    protected $smtpTransport;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /** @var ImapEmailGoogleOauth2Manager */
    protected $imapEmailGoogleOauth2Manager;

    /**
     * Constructor
     *
     * @param \Swift_Mailer      $baseMailer
     * @param ContainerInterface $container
     */
    public function __construct(
        \Swift_Mailer $baseMailer,
        ContainerInterface $container,
        ImapEmailGoogleOauth2Manager $imapEmailGoogleOauth2Manager
    ) {
        $this->baseMailer = $baseMailer;
        $this->container  = $container;
        $this->imapEmailGoogleOauth2Manager = $imapEmailGoogleOauth2Manager;

        $transport = $this->baseMailer->getTransport();
        if ($transport instanceof \Swift_Transport_SpoolTransport) {
            $transport = $this->findRealTransport();
            if (!$transport) {
                $transport = \Swift_NullTransport::newInstance();
            }
        }
        parent::__construct($transport);
    }

    /**
     * Set SmtpTransport instance or create a new if default mailer transport is not smtp
     *
     * @param UserEmailOrigin $userEmailOrigin
     */
    public function prepareSmtpTransport($userEmailOrigin)
    {
        if (!$this->smtpTransport) {
            $username = $userEmailOrigin->getUser();
            /** @var Mcrypt $encoder */
            $encoder  =  $this->container->get('oro_security.encoder.mcrypt');
            $password = $encoder->decryptData($userEmailOrigin->getPassword());
            $host     = $userEmailOrigin->getSmtpHost();
            $port     = $userEmailOrigin->getSmtpPort();
            $security = $userEmailOrigin->getSmtpEncryption();
            $accessToken = $this->imapEmailGoogleOauth2Manager->getAccessTokenWithCheckingExpiration($userEmailOrigin);

            $transport = $this->getTransport();
            if ($transport instanceof \Swift_SmtpTransport
                || $transport instanceof \Swift_Transport_EsmtpTransport) {
                $transport->setHost($host);
                $transport->setPort($port);
                $transport->setEncryption($security);
            } else {
                $transport = \Swift_SmtpTransport::newInstance($host, $port, $security);
            }

            $transport->setUsername($username);

            if ($accessToken === null) {
                $transport->setPassword($password);
            } else {
                $transport->setAuthMode('XOAUTH2');
                $transport->setPassword($accessToken);
            }

            $this->smtpTransport = $transport;
        }
    }

    /**
     * The Transport used to send messages.
     *
     * @return \Swift_Transport|\Swift_SmtpTransport
     */
    public function getTransport()
    {
        if ($this->smtpTransport) {
            return $this->smtpTransport;
        }
        return parent::getTransport();
    }

    /**
     * Register a plugin using a known unique key (e.g. myPlugin).
     *
     * @param \Swift_Events_EventListener $plugin
     * @throws \Oro\Bundle\EmailBundle\Exception\NotSupportedException
     */
    public function registerPlugin(\Swift_Events_EventListener $plugin)
    {
        throw new NotSupportedException('The registerPlugin() is not supported for this mailer.');
    }

    /**
     * Sends the given message.
     *
     * The return value is the number of recipients who were accepted for
     * delivery.
     *
     * @param \Swift_Mime_Message $message
     * @param array               $failedRecipients An array of failures by-reference
     *
     * @return int The number of recipients who were accepted for delivery
     * @throws \Exception
     */
    public function send(\Swift_Mime_Message $message, &$failedRecipients = null)
    {
        $result = 0;
        // start a transport if needed
        $needToStopRealTransport = false;
        if (!$this->getTransport()->isStarted()) {
            $this->getTransport()->start();
            $needToStopRealTransport = true;
        }
        // send a mail
        $sendException = null;
        try {
            if ($this->smtpTransport) {
                $result = $this->smtpTransport->send($message, $failedRecipients);
            } else {
                $result = parent::send($message, $failedRecipients);
            }
        } catch (\Exception $unexpectedEx) {
            $sendException = $unexpectedEx;
        }
        // stop a transport if it was started before
        if ($needToStopRealTransport) {
            try {
                $this->getTransport()->stop();
            } catch (\Exception $ex) {
                // ignore errors here
            }
        }
        // rethrow send failure
        if ($sendException) {
            throw $sendException;
        }

        return $result;
    }

    /**
     * Returns a real transport used to send mails by a mailer specified in the constructor of this class
     *
     * @return \Swift_Transport|null
     */
    protected function findRealTransport()
    {
        $realTransport = null;
        $mailers       = array_keys($this->container->getParameter('swiftmailer.mailers'));
        foreach ($mailers as $name) {
            if ($this->container instanceof IntrospectableContainerInterface
                && !$this->container->initialized(sprintf('swiftmailer.mailer.%s', $name))
            ) {
                continue;
            }
            $mailer = $this->container->get(sprintf('swiftmailer.mailer.%s', $name));
            if ($mailer === $this->baseMailer) {
                $realTransport = $this->container->get(sprintf('swiftmailer.mailer.%s.transport.real', $name));
                break;
            }
        }

        return $realTransport;
    }
}
