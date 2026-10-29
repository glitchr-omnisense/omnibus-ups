<?php

namespace Omnibus\Ups\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Exception\CarrierException;
use Omnibus\Model\Label;
use Omnibus\Request\Request;
use Omnibus\Request\Shipping;
use Omnibus\Ups\Api;
use Omnibus\Ups\Mapping;

/** The Shipping API: the shipment booked, its label (PDF by default, "GIF"/"ZPL" through the option label_format). */
final class ShippingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /** ShippingLabel ImageFormat codes (Shipping API: EPL, SPL, ZPL, GIF, PNG) and their media types. */
    private const FORMATS = ['GIF' => 'image/gif', 'PNG' => 'image/png', 'ZPL' => Label::ZPL, 'EPL' => 'application/epl', 'SPL' => 'application/spl', 'PDF' => Label::PDF];

    public function supports(Request $request): bool
    {
        return $request instanceof Shipping;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Shipping);
        $s = $request->shipment;
        $format = strtoupper((string) $s->option('label_format', 'PDF'));
        $data = $this->api->call('POST', '/api/shipments/'.Api::VERSION.'/ship', ['ShipmentRequest' => [
            'Request' => ['RequestOption' => 'nonvalidate'],
            'Shipment' => array_filter([
                'Description' => $s->option('description', 'Parcel'),
                'Shipper' => Mapping::address($s->sender) + ['ShipperNumber' => $this->api->accountNumber],
                'ShipTo' => Mapping::address($s->recipient),
                'ShipFrom' => Mapping::address($s->sender),
                'PaymentInformation' => ['ShipmentCharge' => [['Type' => '01', 'BillShipper' => ['AccountNumber' => $this->api->accountNumber]]]],
                'Service' => ['Code' => $s->service ?? '11'],
                'ReferenceNumber' => $s->reference ? ['Value' => mb_substr($s->reference, 0, 35)] : null,
                'Package' => array_map([Mapping::class, 'package'], $s->parcels),
            ]),
            'LabelSpecification' => ['LabelImageFormat' => ['Code' => 'PDF' === $format ? 'GIF' : $format], 'LabelStockSize' => ['Height' => '6', 'Width' => '4']],
        ]]);
        $results = $data['ShipmentResponse']['ShipmentResults'] ?? [];
        $packages = $results['PackageResults'] ?? [];
        $first = isset($packages[0]) ? $packages[0] : $packages;
        $number = (string) ($results['ShipmentIdentificationNumber'] ?? $first['TrackingNumber'] ?? '');
        if ('' === $number) {
            throw new CarrierException('ups', 'UPS booked no shipment.');
        }
        $image = $first['ShippingLabel']['GraphicImage'] ?? null;
        $code = strtoupper((string) ($first['ShippingLabel']['ImageFormat']['Code'] ?? 'GIF'));

        $request->setResult(new Label('ups', $number,
            \is_string($image) ? base64_decode($image) : null,
            self::FORMATS[$code] ?? 'application/octet-stream',
            trackingUrl: 'https://www.ups.com/track?tracknum='.rawurlencode($number),
        ));
    }
}
