<?php

namespace Cultpantry\SquareSync\Actions;

use Carbon\CarbonImmutable;
use Cultpantry\SquareSync\Contracts\Sales\SquareCustomer;
use Cultpantry\SquareSync\Contracts\Sales\SquareRefund;
use Cultpantry\SquareSync\Contracts\Sales\SquareRefundLine;
use Cultpantry\SquareSync\Contracts\Sales\SquareSale;
use Cultpantry\SquareSync\Contracts\Sales\SquareSaleLine;

/**
 * Turns one Square order into the SquareSale / SquareRefund the host's
 * recorder receives. Pure mapping -- no API calls, no writes.
 *
 * An order can carry both: line_items are what was sold, returns are what
 * was brought back (a refund creates a separate "return order" whose
 * returns point at the original via source_order_id; an exchange has
 * both). So a sale's money is summed from its own line items rather than
 * taken from the order's totals -- for an exchange, total_money is sales
 * *net of* the return, which would understate the sale and double-count
 * the refund.
 */
class MapSquareOrder
{
    /**
     * @param  array<string, array>  $customers  Square customer objects by id,
     *                                           pre-fetched for a page of orders
     * @param  array<string, int>  $localItemIds  square_object_id => local item id
     */
    public function toSale(array $order, array $customers, array $localItemIds): ?SquareSale
    {
        $lineItems = $order['line_items'] ?? [];

        if ($lineItems === []) {
            return null;
        }

        $currency = $order['total_money']['currency'] ?? $lineItems[0]['total_money']['currency'] ?? 'CAD';

        $lines = [];
        $gross = $discount = $tax = $linesTotal = 0;

        foreach ($lineItems as $item) {
            $quantity = (int) round((float) ($item['quantity'] ?? 1));
            $itemGross = $this->cents($item['gross_sales_money'] ?? null)
                ?: $this->cents($item['base_price_money'] ?? null) * $quantity;

            $gross += $itemGross;
            $discount += $this->cents($item['total_discount_money'] ?? null);
            $tax += $this->cents($item['total_tax_money'] ?? null);
            $linesTotal += $this->cents($item['total_money'] ?? null);

            $variationId = $item['catalog_object_id'] ?? null;

            $lines[] = new SquareSaleLine(
                squareLineUid: (string) ($item['uid'] ?? ''),
                squareVariationId: $variationId,
                localItemId: $variationId !== null ? ($localItemIds[$variationId] ?? null) : null,
                name: $item['name'] ?? 'Custom amount',
                variationName: $item['variation_name'] ?? null,
                quantity: $quantity,
                unitPrice: $this->decimal($this->cents($item['base_price_money'] ?? null)),
                discount: $this->decimal($this->cents($item['total_discount_money'] ?? null)),
                tax: $this->decimal($this->cents($item['total_tax_money'] ?? null)),
                total: $this->decimal($this->cents($item['total_money'] ?? null)),
            );
        }

        // Service charges aren't line items; folding them into the
        // subtotal keeps subtotal - discount + tax + tip = total.
        $serviceCharges = $this->cents($order['total_service_charge_money'] ?? null);
        $tip = $this->cents($order['total_tip_money'] ?? null);

        return new SquareSale(
            squareOrderId: $order['id'],
            locationId: $order['location_id'] ?? '',
            channel: $this->channel($order),
            sourceName: $order['source']['name'] ?? null,
            placedAt: $this->time($order['closed_at'] ?? $order['created_at'] ?? null),
            currency: $currency,
            subtotal: $this->decimal($gross + $serviceCharges),
            discount: $this->decimal($discount),
            tax: $this->decimal($tax),
            tip: $this->decimal($tip),
            total: $this->decimal($linesTotal + $serviceCharges + $tip),
            paymentMethod: $this->paymentMethod($order['tenders'] ?? []),
            customer: $this->customer($order, $customers),
            lines: $lines,
            raw: $order,
            environment: $this->environment(),
        );
    }

    /**
     * @param  array<string, int>  $localItemIds  square_object_id => local item id
     */
    public function toRefund(array $order, array $localItemIds): ?SquareRefund
    {
        $returns = $order['returns'] ?? [];
        $sourceOrderId = collect($returns)->pluck('source_order_id')->filter()->first();

        if ($returns === [] || $sourceOrderId === null) {
            return null;
        }

        $lines = [];
        $linesTotal = 0;

        foreach ($returns as $return) {
            foreach ($return['return_line_items'] ?? [] as $item) {
                $amount = abs($this->cents($item['total_money'] ?? null));
                $linesTotal += $amount;
                $variationId = $item['catalog_object_id'] ?? null;

                $lines[] = new SquareRefundLine(
                    squareLineUid: $item['source_line_item_uid'] ?? null,
                    squareVariationId: $variationId,
                    localItemId: $variationId !== null ? ($localItemIds[$variationId] ?? null) : null,
                    name: $item['name'] ?? 'Custom amount',
                    quantity: (int) round((float) ($item['quantity'] ?? 1)),
                    amount: $this->decimal($amount),
                );
            }
        }

        $returnTotal = $order['return_amounts']['total_money'] ?? null;
        $amount = $returnTotal !== null ? abs($this->cents($returnTotal)) : $linesTotal;
        $refund = $order['refunds'][0] ?? [];

        return new SquareRefund(
            // Keyed on the refund when Square attaches one, else on the
            // return order itself -- either is stable across pulls.
            squareRefundId: $refund['id'] ?? $order['id'],
            squareOrderId: $sourceOrderId,
            refundedAt: $this->time($order['closed_at'] ?? $order['created_at'] ?? null),
            currency: $returnTotal['currency'] ?? $order['total_money']['currency'] ?? 'CAD',
            amount: $this->decimal($amount),
            reason: $refund['reason'] ?? null,
            lines: $lines,
            raw: $order,
            environment: $this->environment(),
        );
    }

    private function environment(): string
    {
        return config('square-sync.environment') === SquareSale::ENV_PRODUCTION
            ? SquareSale::ENV_PRODUCTION
            : SquareSale::ENV_SANDBOX;
    }

    /**
     * Square doesn't label an order's channel directly; source.name is
     * the closest thing. VERIFY against real sandbox POS and invoice
     * orders -- this is a best guess from Square's documented examples.
     */
    private function channel(array $order): string
    {
        $source = strtolower($order['source']['name'] ?? '');

        return match (true) {
            str_contains($source, 'invoice') => SquareSale::CHANNEL_INVOICE,
            str_contains($source, 'point of sale'), $source === 'square pos' => SquareSale::CHANNEL_POS,
            default => SquareSale::CHANNEL_OTHER,
        };
    }

    private function paymentMethod(array $tenders): string
    {
        $types = collect($tenders)->pluck('type')->filter()->unique()->values();

        if ($types->count() > 1) {
            return SquareSale::PAYMENT_MIXED;
        }

        return match ($types->first()) {
            'CARD' => SquareSale::PAYMENT_CARD,
            'CASH' => SquareSale::PAYMENT_CASH,
            default => SquareSale::PAYMENT_OTHER,
        };
    }

    private function customer(array $order, array $customers): ?SquareCustomer
    {
        $customerId = $order['customer_id'] ?? null;

        if ($customerId !== null && isset($customers[$customerId])) {
            $customer = $customers[$customerId];
            $name = trim(($customer['given_name'] ?? '').' '.($customer['family_name'] ?? ''));

            return new SquareCustomer(
                squareCustomerId: $customerId,
                name: $name !== '' ? $name : ($customer['company_name'] ?? null),
                email: $this->blankToNull($customer['email_address'] ?? null),
                phone: $this->blankToNull($customer['phone_number'] ?? null),
            );
        }

        // No directory customer -- an invoice or pickup order can still
        // name its recipient on a fulfillment.
        foreach ($order['fulfillments'] ?? [] as $fulfillment) {
            $recipient = $fulfillment['pickup_details']['recipient']
                ?? $fulfillment['shipment_details']['recipient']
                ?? $fulfillment['delivery_details']['recipient']
                ?? null;

            if ($recipient && (filled($recipient['email_address'] ?? null) || filled($recipient['phone_number'] ?? null) || filled($recipient['display_name'] ?? null))) {
                return new SquareCustomer(
                    squareCustomerId: $recipient['customer_id'] ?? null,
                    name: $this->blankToNull($recipient['display_name'] ?? null),
                    email: $this->blankToNull($recipient['email_address'] ?? null),
                    phone: $this->blankToNull($recipient['phone_number'] ?? null),
                );
            }
        }

        return null;
    }

    private function cents(?array $money): int
    {
        return (int) ($money['amount'] ?? 0);
    }

    /**
     * Square money is integer minor units; the host gets a 2-dp decimal
     * string. (Fine for CAD/USD -- a zero-decimal currency would need the
     * currency's exponent here.)
     */
    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function time(?string $timestamp): CarbonImmutable
    {
        return $timestamp !== null ? CarbonImmutable::parse($timestamp) : CarbonImmutable::now();
    }

    private function blankToNull(?string $value): ?string
    {
        return filled($value) ? trim($value) : null;
    }
}
