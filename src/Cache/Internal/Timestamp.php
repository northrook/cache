<?php

namespace Northrook\Cache\Internal;

/**
 * @internal
 *
 * @author  Martin Nielsen <mn@northrook.com>
 */
final class Timestamp implements \Stringable
{
    public const FORMAT_HUMAN            = 'd-m-Y H:i:s';
    public const FORMAT_W3C              = 'Y-m-d\TH:i:sP';
    public const FORMAT_RFC3339          = 'Y-m-d\TH:i:sP';
    public const FORMAT_RFC3339_EXTENDED = 'Y-m-d\TH:i:s.vP';

    /**
     * @var array<string, \DateTimeZone>
     */
    private static array $TIMEZONES = [];

    private readonly \DateTimeImmutable $dateTimeImmutable;

    public readonly int    $timestamp;
    public readonly string $datetime;

    public function __construct( string $timezone = 'UTC' ) {
        $this->datetime( $timezone );

        $this->timestamp = $this->dateTimeImmutable->getTimestamp();
        $this->datetime  = $this->format( Timestamp::FORMAT_HUMAN );
    }

    public function __toString() : string {
        return $this->datetime;
    }

    public function format( string $format = Timestamp::FORMAT_HUMAN, bool $includeTimezone = true ) : string {

        $time = $this->dateTimeImmutable->format( $format );

        return $includeTimezone ? $time . ' ' . $this->dateTimeImmutable->getTimezone()->getName() : $time;
    }

    private function timezone( string $timezone ) : \DateTimeZone {
        try {
            return Timestamp::$TIMEZONES[ $timezone ] ??= new \DateTimeZone( $timezone );
        }
        catch ( \Exception $exception ) {
            throw new \InvalidArgumentException(
                message  : "Unable to create a new DateTimeZone object for $timezone.",
                code     : 500,
                previous : $exception,
            );
        }
    }

    private function datetime( string $timezone ) : void {
        try {
            $this->dateTimeImmutable = new \DateTimeImmutable( 'now', $this->timezone( $timezone ) );
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