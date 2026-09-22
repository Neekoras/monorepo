<?php

declare(strict_types=1);

namespace Utopia\Messaging\Exception;

/**
 * A message, or one of its fields, that no provider could deliver.
 *
 * Thrown before any request leaves the process, so a caller can stop retrying
 * and report the reason to whoever supplied the input. {@see $type} carries
 * one of the constants below and {@see $value} the offending input, so the
 * caller can map the failure to its own error catalogue without parsing the
 * message text.
 */
class InvalidArgumentException extends \InvalidArgumentException
{
    public const string MESSAGE_TYPE = 'message_type';

    public const string MESSAGE_EMPTY = 'message_empty';

    public const string TOO_MANY_RECIPIENTS = 'too_many_recipients';

    public const string RECIPIENT_EMPTY = 'recipient_empty';

    public const string RECIPIENT_MALFORMED = 'recipient_malformed';

    public const string RECIPIENT_DOMAIN_INVALID = 'recipient_domain_invalid';

    public const string RECIPIENT_DOMAIN_RESERVED = 'recipient_domain_reserved';

    public const string NAME_MALFORMED = 'name_malformed';

    public const string SENDER_MALFORMED = 'sender_malformed';

    public function __construct(
        public readonly string $type,
        string $message,
        public readonly ?string $value = null,
    ) {
        parent::__construct($message);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }
}
