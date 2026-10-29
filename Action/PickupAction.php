<?php

namespace Omnibus\Ups\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Model\Address;
use Omnibus\Model\PickupPoint;
use Omnibus\Request\Pickup;
use Omnibus\Request\Request;
use Omnibus\Ups\Api;

/** The Locator API: UPS Access Points near an address, nearest first. */
final class PickupAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Pickup;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Pickup);
        $near = $request->near;
        $data = $this->api->call('POST', '/api/locations/v2/search/availabilities/64', ['LocatorRequest' => [
            'Request' => ['RequestAction' => 'Locator', 'RequestOption' => '64'],
            'OriginAddress' => ['AddressKeyFormat' => array_filter(['AddressLine' => $near->line(0) ?: null, 'PoliticalDivision2' => $near->city, 'PostcodePrimaryLow' => $near->postcode, 'CountryCode' => strtoupper($near->country)])],
            'Translate' => ['Locale' => 'en_US'],
            'UnitOfMeasurement' => ['Code' => 'KM'],
            'LocationSearchCriteria' => ['MaximumListSize' => (string) min(50, max(1, $request->limit)), 'SearchRadius' => '25', 'AccessPointSearch' => ['AccessPointStatus' => '01']],
        ]], ['Locale' => 'en_US']);
        $points = [];
        foreach ($data['LocatorResponse']['SearchResults']['DropLocation'] ?? [] as $location) {
            $key = $location['AddressKeyFormat'] ?? [];
            $hours = self::hours($location['OperatingHours']['StandardHours'] ?? []);
            $points[] = new PickupPoint('ups', (string) ($location['AccessPointInformation']['PublicAccessPointID'] ?? $location['LocationID'] ?? ''), (string) ($key['ConsigneeName'] ?? 'UPS Access Point'),
                new Address((string) ($key['ConsigneeName'] ?? ''), [(string) ($key['AddressLine'] ?? '')], (string) ($key['PostcodePrimaryLow'] ?? ''), (string) ($key['PoliticalDivision2'] ?? ''), (string) ($key['CountryCode'] ?? $near->country)),
                isset($location['Geocode']['Latitude']) ? (float) $location['Geocode']['Latitude'] : null, isset($location['Geocode']['Longitude']) ? (float) $location['Geocode']['Longitude'] : null,
                $hours, isset($location['Distance']['Value']) ? (int) round(((float) $location['Distance']['Value']) * 1000) : null);
        }
        $request->setResult(\array_slice($points, 0, $request->limit));
    }

    /**
     * The regular hours (HoursType 10, else the first set) by ISO weekday: UPS numbers Sunday 1
     * to Saturday 7 and writes military HHMM ("930", "0" at midnight); a day with
     * ClosedIndicator is left out, one with Open24HoursIndicator is 00:00-24:00.
     *
     * @return array<int, list<array{string, string}>>
     */
    private static function hours(array $standard): array
    {
        $sets = isset($standard['DayOfWeek']) ? [$standard] : array_values($standard); // v1 may send one object
        $set = [];
        foreach ($sets as $candidate) {
            if ('10' === (string) ($candidate['HoursType'] ?? '')) {
                $set = $candidate;
                break;
            }
        }
        $set = $set ?: ($sets[0] ?? []);
        $days = $set['DayOfWeek'] ?? [];
        $days = isset($days['Day']) ? [$days] : $days;
        $hours = [];
        foreach ($days as $day) {
            $ups = (int) ($day['Day'] ?? 0);
            if ($ups < 1 || $ups > 7 || \array_key_exists('ClosedIndicator', $day)) {
                continue;
            }
            $iso = 1 === $ups ? 7 : $ups - 1;
            if (\array_key_exists('Open24HoursIndicator', $day)) {
                $hours[$iso] = [['00:00', '24:00']];
            } elseif (isset($day['OpenHours'], $day['CloseHours'])) {
                $close = self::time((string) $day['CloseHours']);
                $hours[$iso] = [[self::time((string) $day['OpenHours']), '00:00' === $close ? '24:00' : $close]]; // closing at midnight
            }
        }
        ksort($hours);

        return $hours;
    }

    private static function time(string $military): string
    {
        $t = str_pad(preg_replace('/\D/', '', $military) ?: '0', 4, '0', \STR_PAD_LEFT);

        return substr($t, 0, 2).':'.substr($t, 2, 2);
    }
}
