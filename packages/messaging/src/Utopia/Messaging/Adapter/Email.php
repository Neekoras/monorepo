<?php

namespace Utopia\Messaging\Adapter;

use Utopia\Messaging\Adapter;
use Utopia\Messaging\Messages\Email as EmailMessage;

abstract class Email extends Adapter
{
    protected const TYPE = 'email';
    protected const MESSAGE_TYPE = EmailMessage::class;

    protected const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024; // 25MB

    public function getType(): string
    {
        return static::TYPE;
    }

    public function getMessageType(): string
    {
        return static::MESSAGE_TYPE;
    }

    /**
     * Format an email address with an optional display name (RFC 5322).
     *
     * When the display name contains any RFC 5322 special character it is
     * wrapped in a quoted-string (with embedded quotes and backslashes
     * escaped). Without this, a name such as "Acme, Inc." or "Doe <John>"
     * produces a malformed address that providers reject.
     */
    protected function formatAddress(string $email, ?string $name): string
    {
        if (\in_array($name, [null, '', '0'], true)) {
            return $email;
        }

        if (preg_match('/[,;:@<>()\[\]\\\\".]/', $name)) {
            $name = '"' . addcslashes($name, '"\\') . '"';
        }

        return "{$name} <{$email}>";
    }

    /**
     * Process an email message.
     *
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     *
     * @throws \Exception
     */
    abstract protected function process(EmailMessage $message): array;
}
