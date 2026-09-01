<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which conversation a comment belongs to.
 *
 * A ticket carries two independent threads:
 *
 *   Customer  the conversation with the customer. Everyone who can read the
 *             ticket can read and write here.
 *   Internal  the delivery team talking among themselves. Customers must never
 *             receive these rows, their ids, their authors or their count.
 *
 * The enum is the single definition of that rule: `customerFacing()` below is
 * what the query scope, the policy and the notification dispatcher all ask, so
 * adding a third stream later means answering the question once.
 */
enum CommentStream: string
{
    case Customer = 'customer';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer conversation',
            self::Internal => 'Internal notes',
        };
    }

    /**
     * May a customer ever observe this stream?
     */
    public function isCustomerFacing(): bool
    {
        return $this === self::Customer;
    }

    /**
     * The streams a customer is allowed to read, as raw column values.
     *
     * Used to build the SQL restriction. Derived from the cases rather than
     * hard-coded so a new stream is internal unless it says otherwise.
     *
     * @return array<int, string>
     */
    public static function customerFacingValues(): array
    {
        return array_values(array_map(
            fn (self $stream): string => $stream->value,
            array_filter(self::cases(), fn (self $stream): bool => $stream->isCustomerFacing())
        ));
    }

    /**
     * The stream a comment falls into when nothing says otherwise.
     *
     * Internal, so that a code path which forgets to choose cannot publish to
     * a customer. The database column defaults the same way.
     */
    public static function default(): self
    {
        return self::Internal;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
