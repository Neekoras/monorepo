<?php

namespace Utopia\Messaging\Adapter;

use Utopia\Messaging\Adapter;
use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Messaging\Message;
use Utopia\Messaging\Messages\Email as EmailMessage;

abstract class Email extends Adapter
{
    protected const TYPE = 'email';
    protected const MESSAGE_TYPE = EmailMessage::class;

    protected const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024; // 25MB

    /**
     * Whether this transport routes mail to reserved domains (RFC 2606,
     * RFC 6761). A relay pointed at a local catcher does; every hosted
     * provider refuses `user@example.com` at the API, so the default rejects
     * such recipients before a request is spent on them.
     */
    protected const bool DELIVERS_TO_RESERVED_DOMAINS = false;

    /**
     * Domains reserved for documentation and testing (RFC 2606, RFC 6761),
     * as top-level labels and as whole domains.
     *
     * @var array<string>
     */
    private const array RESERVED_DOMAINS = ['test', 'example', 'invalid', 'localhost', 'example.com', 'example.net', 'example.org'];

    public function getType(): string
    {
        return static::TYPE;
    }

    public function getMessageType(): string
    {
        return static::MESSAGE_TYPE;
    }

    /**
     * @throws InvalidArgumentException When a recipient's domain is reserved and this transport cannot route to it.
     */
    #[\Override]
    protected function validate(Message $message): void
    {
        if (static::DELIVERS_TO_RESERVED_DOMAINS || !$message instanceof EmailMessage) {
            return;
        }

        foreach ([...$message->getTo(), ...($message->getCC() ?? []), ...($message->getBCC() ?? [])] as $recipient) {
            $domain = strtolower(substr($recipient['email'], (int) strrpos($recipient['email'], '@') + 1));
            $tld = substr($domain, (int) strrpos($domain, '.') + 1);

            if (\in_array($tld, self::RESERVED_DOMAINS, true) || array_any(self::RESERVED_DOMAINS, static fn(string $reserved): bool => $domain === $reserved || str_ends_with($domain, '.' . $reserved))) {
                throw new InvalidArgumentException(
                    InvalidArgumentException::RECIPIENT_DOMAIN_RESERVED,
                    "Email address \"{$recipient['email']}\" uses a reserved domain that cannot receive mail.",
                    $recipient['email'],
                );
            }
        }
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
