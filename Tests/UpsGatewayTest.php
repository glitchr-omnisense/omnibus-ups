<?php

namespace Omnibus\Ups\Tests;

use Omnibus\Model\TrackingStatus;
use Omnibus\Testing\Fixtures;
use Omnibus\Ups\UpsGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UpsGatewayTest extends TestCase
{
    private array $calls = [];

    private function gateway(): \Omnibus\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringStartsWith('https://wwwcie.ups.com', $url, 'the sandbox');
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$method, $path, \is_string($options['body'] ?? null) && str_starts_with($options['body'], '{') ? json_decode($options['body'], true) : []];

            return match (true) {
                str_ends_with($path, '/oauth/token') => new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 14399])),
                str_ends_with($path, '/Shop') => new MockResponse(json_encode(['RateResponse' => ['RatedShipment' => [
                    ['Service' => ['Code' => '07'], 'TotalCharges' => ['CurrencyCode' => 'EUR', 'MonetaryValue' => '42.10'], 'GuaranteedDelivery' => ['BusinessDaysInTransit' => '1']],
                    ['Service' => ['Code' => '11'], 'TotalCharges' => ['CurrencyCode' => 'EUR', 'MonetaryValue' => '12.90']],
                ]]])),
                str_ends_with($path, '/ship') => new MockResponse(json_encode(['ShipmentResponse' => ['ShipmentResults' => ['ShipmentIdentificationNumber' => '1Z999AA10123456784', 'PackageResults' => [['TrackingNumber' => '1Z999AA10123456784', 'ShippingLabel' => ['ImageFormat' => ['Code' => 'GIF'], 'GraphicImage' => base64_encode('GIF89a')]]]]]])),
                str_contains($path, '/track/v1/details/') => new MockResponse(json_encode(['trackResponse' => ['shipment' => [['package' => [['activity' => [
                    ['date' => '20261002', 'time' => '101500', 'status' => ['type' => 'D', 'description' => 'Delivered', 'code' => 'KB'], 'location' => ['address' => ['city' => 'STRASBOURG', 'countryCode' => 'FR']]],
                    ['date' => '20261001', 'time' => '080000', 'status' => ['type' => 'I', 'description' => 'Departed from facility', 'code' => 'DP']],
                ]]]]]]])),
                str_contains($path, '/locations/') => new MockResponse(json_encode(['LocatorResponse' => ['SearchResults' => ['DropLocation' => [['LocationID' => 'L1', 'AccessPointInformation' => ['PublicAccessPointID' => 'U12345'], 'AddressKeyFormat' => ['ConsigneeName' => 'TABAC DE LA GARE', 'AddressLine' => '2 PLACE DE LA GARE', 'PoliticalDivision2' => 'STRASBOURG', 'PostcodePrimaryLow' => '67000', 'CountryCode' => 'FR'], 'Geocode' => ['Latitude' => '48.585', 'Longitude' => '7.735'], 'Distance' => ['Value' => '0.4'], 'OperatingHours' => ['StandardHours' => [
                    ['HoursType' => '50', 'DayOfWeek' => [['Day' => '2', 'LatestDropOffHours' => '1700']]],
                    ['HoursType' => '10', 'DayOfWeek' => [['Day' => '1', 'ClosedIndicator' => ''], ['Day' => '2', 'OpenHours' => '930', 'CloseHours' => '1900'], ['Day' => '3', 'OpenHours' => '0', 'CloseHours' => '0', 'Open24HoursIndicator' => ''], ['Day' => '7', 'OpenHours' => '0800', 'CloseHours' => '1230']]],
                ]]]]]]])),
                default => new MockResponse(json_encode(['response' => ['errors' => [['code' => '404', 'message' => 'No such resource '.$path]]]]), ['http_code' => 404]),
            };
        });

        return (new UpsGatewayFactory($http))->create(['client_id' => 'id', 'client_secret' => 'secret', 'account_number' => 'A1B2C3', 'sandbox' => true]);
    }

    public function testRatesComeCheapestFirstWithTheServiceNamed(): void
    {
        $rates = $this->gateway()->rate(Fixtures::shipment());
        self::assertSame(['11', '07'], array_map(fn ($r) => $r->service, $rates));
        self::assertSame(1290, $rates[0]->amount);
        self::assertSame('Standard', $rates[0]->label);
        self::assertSame(1, $rates[1]->days);
        self::assertSame('A1B2C3', $this->calls[1][2]['RateRequest']['Shipment']['Shipper']['ShipperNumber']);
        self::assertSame('KGS', $this->calls[1][2]['RateRequest']['Shipment']['Package'][0]['PackageWeight']['UnitOfMeasurement']['Code']);
    }

    public function testAShipmentIsBookedWithItsLabel(): void
    {
        $label = $this->gateway()->ship(Fixtures::shipment());
        self::assertSame('1Z999AA10123456784', $label->trackingNumber);
        self::assertSame('GIF89a', $label->content);
        self::assertSame('image/gif', $label->format);
        self::assertStringContainsString('1Z999AA10123456784', $label->trackingUrl);
    }

    public function testTrackingReadsTheActivitiesOldestFirst(): void
    {
        $tracking = $this->gateway()->track('1Z999AA10123456784');
        self::assertSame(TrackingStatus::DELIVERED, $tracking->status);
        self::assertCount(2, $tracking->events);
        self::assertSame(TrackingStatus::IN_TRANSIT, $tracking->events[0]->status);
        self::assertSame('STRASBOURG FR', $tracking->latest()->location);
    }

    public function testAccessPointsAreFoundNearAnAddress(): void
    {
        $points = $this->gateway()->pickupPoints(Fixtures::shipment()->recipient, 5);
        self::assertCount(1, $points);
        self::assertSame('U12345', $points[0]->id);
        self::assertSame('TABAC DE LA GARE', $points[0]->name);
        self::assertSame(400, $points[0]->distance);
        self::assertSame('67000', $points[0]->address->postcode);
    }

    /**
     * The Locator (UPS-API/api-documentation, Locator.yaml): StandardHours is a list by HoursType
     * (10 the regular hours), Day is 1 for Sunday to 7 for Saturday, hours are military HHMM
     * ("930", "0" for midnight), ClosedIndicator marks a closed day and Open24HoursIndicator a
     * day open around the clock. Read as ISO weekdays and "HH:MM".
     */
    public function testAccessPointHoursAreIsoWeekdaysInHoursAndMinutes(): void
    {
        $points = $this->gateway()->pickupPoints(Fixtures::shipment()->recipient, 5);
        self::assertSame([1 => [['09:30', '19:00']], 2 => [['00:00', '24:00']], 6 => [['08:00', '12:30']]], $points[0]->openingHours);
    }

    public function testTheCredentialsAreRequired(): void
    {
        $this->expectException(\Omnibus\Exception\InvalidConfigException::class);
        (new UpsGatewayFactory())->create(['client_id' => 'x']);
    }
}
