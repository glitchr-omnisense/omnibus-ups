<?php

namespace Omnibus\Ups\Tests;

use Omnibus\GatewayFactoryInterface;
use Omnibus\Testing\GatewayContractTests;
use Omnibus\Ups\UpsGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The conformance suite on UPS's recorded answers (the Customer Integration Environment). */
final class UpsContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new UpsGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        return ['client_id' => 'id', 'client_secret' => 'secret', 'account_number' => 'A1B2C3', 'sandbox' => true];
    }

    protected function sessionRoutes(): array
    {
        return ['POST /security/v1/oauth/token'];
    }

    protected function contractTrackingNumber(): string
    {
        return '1Z999AA10123456784';
    }

    protected function recordedAnswers(): array
    {
        return [
            'POST /security/v1/oauth/token' => ['access_token' => 'tok', 'expires_in' => 14399],
            '/Shop' => ['RateResponse' => ['RatedShipment' => [
                ['Service' => ['Code' => '07'], 'TotalCharges' => ['CurrencyCode' => 'EUR', 'MonetaryValue' => '42.10'], 'GuaranteedDelivery' => ['BusinessDaysInTransit' => '1']],
                ['Service' => ['Code' => '11'], 'TotalCharges' => ['CurrencyCode' => 'EUR', 'MonetaryValue' => '12.90']],
            ]]],
            '/ship' => ['ShipmentResponse' => ['ShipmentResults' => ['ShipmentIdentificationNumber' => '1Z999AA10123456784', 'PackageResults' => [['TrackingNumber' => '1Z999AA10123456784', 'ShippingLabel' => ['ImageFormat' => ['Code' => 'GIF'], 'GraphicImage' => base64_encode('GIF89a')]]]]]],
            '/track/v1/details/' => ['trackResponse' => ['shipment' => [['package' => [['activity' => [
                ['date' => '20261002', 'time' => '101500', 'status' => ['type' => 'D', 'description' => 'Delivered', 'code' => 'KB'], 'location' => ['address' => ['city' => 'STRASBOURG', 'countryCode' => 'FR']]],
                ['date' => '20261001', 'time' => '080000', 'status' => ['type' => 'I', 'description' => 'Departed from facility', 'code' => 'DP']],
            ]]]]]]],
            '/locations/' => ['LocatorResponse' => ['SearchResults' => ['DropLocation' => [
                ['LocationID' => 'L1', 'AccessPointInformation' => ['PublicAccessPointID' => 'U12345'], 'AddressKeyFormat' => ['ConsigneeName' => 'TABAC DE LA GARE', 'AddressLine' => '2 PLACE DE LA GARE', 'PoliticalDivision2' => 'STRASBOURG', 'PostcodePrimaryLow' => '67000', 'CountryCode' => 'FR'], 'Geocode' => ['Latitude' => '48.585', 'Longitude' => '7.735'], 'Distance' => ['Value' => '0.4']],
                ['LocationID' => 'L2', 'AccessPointInformation' => ['PublicAccessPointID' => 'U12346'], 'AddressKeyFormat' => ['ConsigneeName' => 'PRESSE DU MARCHE', 'AddressLine' => '5 RUE DU MARCHE', 'PoliticalDivision2' => 'STRASBOURG', 'PostcodePrimaryLow' => '67000', 'CountryCode' => 'FR'], 'Distance' => ['Value' => '0.9']],
                ['LocationID' => 'L3', 'AccessPointInformation' => ['PublicAccessPointID' => 'U12347'], 'AddressKeyFormat' => ['ConsigneeName' => 'LOCKER GARE', 'AddressLine' => '1 RUE DE LA GARE', 'PoliticalDivision2' => 'STRASBOURG', 'PostcodePrimaryLow' => '67000', 'CountryCode' => 'FR'], 'Distance' => ['Value' => '1.2']],
            ]]]],
        ];
    }
}
