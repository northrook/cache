<?php

namespace Northrook\Cache;

/**
 * @internal
 *
 * @author  Martin Nielsen <mn@northrook.com>
 */
final class Timestamp
{
    public const FORMAT_HUMAN            = 'd-m-Y H:i:s';
    public const FORMAT_W3C              = 'Y-m-d\TH:i:sP';
    public const FORMAT_RFC3339          = 'Y-m-d\TH:i:sP';
    public const FORMAT_RFC3339_EXTENDED = 'Y-m-d\TH:i:s.vP';

    private readonly \DateTimeImmutable $dateTimeImmutable;

    public readonly int    $timestamp;
    public readonly string $datetime;
    public readonly string $timezone;

    public function __construct(
        string | \DateTimeInterface $dateTime = 'now',
        string | \DateTimeZone      $timezone = 'UTC',
        string                      $format = Timestamp::FORMAT_HUMAN,
    ) {
        $this->setDateTime( $dateTime, $timezone );

        $this->timestamp = $this->dateTimeImmutable->getTimestamp();
        $this->timezone  = $this->dateTimeImmutable->getTimezone()->getName();
        $this->datetime  = $this->dateTimeImmutable->format( $format ) . ' ' . $this->timezone;
    }

    private function setDateTime(
        string | \DateTimeInterface $dateTime = 'now',
        string | \DateTimeZone      $timezone = 'UTC',
    ) : void {
        try {
            $this->dateTimeImmutable = new \DateTimeImmutable( $dateTime, timezone_open( $timezone ) ?: null );
        }
        catch ( \Exception $exception ) {
            throw new \InvalidArgumentException(
                message  : "Unable to create a new DateTimeImmutable object for $timezone.",
                code     : 500,
                previous : $exception,
            );
        }
    }
}