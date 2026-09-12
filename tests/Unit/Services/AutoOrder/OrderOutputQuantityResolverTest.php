<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\QuantityType;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderOutputQuantityResolver;
use Tests\TestCase;

class OrderOutputQuantityResolverTest extends TestCase
{
    public function test_six_pack_ordering_code_keeps_case_quantity_and_uses_packs_per_case_for_display_capacity(): void
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:4901411004754' => (object) ['quantity' => 6],
        ]);
        $this->setPrivateProperty($resolver, 'purchaseUnitPriceCache', [
            999006 => 215.0,
        ]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 1,
            'ordering_code' => '4901411004754',
            'purchase_unit_price' => 5160,
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 24,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame('4901411004754', $output['ordering_code']);
        $this->assertSame(4, $output['display_capacity']);
        $this->assertSame(1, $output['order_quantity']);
        $this->assertSame(1, $output['case_quantity']);
        $this->assertSame(0, $output['piece_quantity']);
        $this->assertSame('ケース', $output['unit_label']);
    }

    public function test_piece_quantity_is_converted_to_six_pack_quantity_for_output(): void
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:4901411004754' => (object) ['quantity' => 6],
        ]);
        $this->setPrivateProperty($resolver, 'purchaseUnitPriceCache', [
            999006 => 215.0,
        ]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => QuantityType::PIECE,
            'order_quantity' => 24,
            'ordering_code' => '4901411004754',
            'purchase_unit_price' => 5160,
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 24,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame('4901411004754', $output['ordering_code']);
        $this->assertSame(4, $output['display_capacity']);
        $this->assertSame(1, $output['order_quantity']);
        $this->assertSame(1, $output['case_quantity']);
        $this->assertSame(0, $output['piece_quantity']);
        $this->assertSame('ケース', $output['unit_label']);
    }

    public function test_piece_quantity_is_output_as_six_pack_case_quantity(): void
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:4901411004754' => (object) ['quantity' => 6],
        ]);
        $this->setPrivateProperty($resolver, 'purchaseUnitPriceCache', [
            999006 => 215.0,
        ]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => QuantityType::PIECE,
            'order_quantity' => 1,
            'ordering_code' => '4901411004754',
            'purchase_unit_price' => 215,
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 24,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame(1, $output['order_quantity']);
        $this->assertSame(1, $output['case_quantity']);
        $this->assertSame(0, $output['piece_quantity']);
    }

    public function test_many_piece_quantities_are_integer_six_pack_case_quantities(): void
    {
        $resolver = $this->sixPackResolver();

        for ($pieceQuantity = 0; $pieceQuantity <= 49; $pieceQuantity++) {
            $candidate = $this->sixPackCandidate(QuantityType::PIECE, $pieceQuantity, 215);

            $output = $resolver->resolve($candidate);
            $expected = $this->expectedSixPackCaseQuantityFromPieces($pieceQuantity);

            $this->assertIsInt($output['order_quantity'], "piece={$pieceQuantity}");
            $this->assertSame($expected, $output['order_quantity'], "piece={$pieceQuantity}");
            $this->assertSame($expected, $output['case_quantity'], "piece={$pieceQuantity}");
            $this->assertSame(0, $output['piece_quantity'], "piece={$pieceQuantity}");
            $this->assertSame((string) $expected, (string) $output['order_quantity'], "piece={$pieceQuantity}");
        }
    }

    public function test_many_case_quantities_are_integer_six_pack_case_quantities(): void
    {
        $resolver = $this->sixPackResolver();

        for ($caseQuantity = 0; $caseQuantity <= 10; $caseQuantity++) {
            $candidate = $this->sixPackCandidate(QuantityType::CASE, $caseQuantity, 5160);

            $output = $resolver->resolve($candidate);
            $expected = $caseQuantity;

            $this->assertIsInt($output['order_quantity'], "case={$caseQuantity}");
            $this->assertSame($expected, $output['order_quantity'], "case={$caseQuantity}");
            $this->assertSame($expected, $output['case_quantity'], "case={$caseQuantity}");
            $this->assertSame(0, $output['piece_quantity'], "case={$caseQuantity}");
            $this->assertSame((string) $expected, (string) $output['order_quantity'], "case={$caseQuantity}");
        }
    }

    public function test_normal_ordering_code_is_not_replaced_by_preferred_six_pack_code(): void
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:4900000000001' => null,
        ]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => QuantityType::PIECE,
            'order_quantity' => 24,
            'ordering_code' => '4900000000001',
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 24,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame('4900000000001', $output['ordering_code']);
        $this->assertNull($output['ordering_unit_quantity']);
        $this->assertSame(24, $output['order_quantity']);
        $this->assertSame(0, $output['case_quantity']);
        $this->assertSame(24, $output['piece_quantity']);
        $this->assertSame('バラ', $output['unit_label']);
    }

    public function test_alphanumeric_ordering_code_is_not_zero_padded_for_output(): void
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:SN6-93-05' => null,
        ]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 1,
            'ordering_code' => 'SN6-93-05',
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 6,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame('SN6-93-05', $output['ordering_code']);
        $this->assertNull($output['ordering_unit_quantity']);
        $this->assertSame(1, $output['case_quantity']);
        $this->assertSame(0, $output['piece_quantity']);
    }

    public function test_zero_padded_alphanumeric_ordering_code_is_unpadded_for_output(): void
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:SN6-93-05' => null,
        ]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 1,
            'ordering_code' => '0000SN6-93-05',
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 6,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame('SN6-93-05', $output['ordering_code']);
        $this->assertNull($output['ordering_unit_quantity']);
    }

    public function test_alphanumeric_fallback_ordering_code_is_not_zero_padded_for_output(): void
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'janCodeCache', [
            999006 => 'SN6-93-05',
        ]);
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:SN6-93-05' => null,
        ]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 1,
            'ordering_code' => null,
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 6,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame('SN6-93-05', $output['ordering_code']);
    }

    public function test_missing_ordering_code_is_not_replaced_by_preferred_six_pack_code(): void
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'janCodeCache', [
            999006 => '4900000000001',
        ]);
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:4900000000001' => null,
        ]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => QuantityType::PIECE,
            'order_quantity' => 24,
            'ordering_code' => null,
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 24,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame('4900000000001', $output['ordering_code']);
        $this->assertNull($output['ordering_unit_quantity']);
        $this->assertSame(24, $output['order_quantity']);
        $this->assertSame(0, $output['case_quantity']);
        $this->assertSame(24, $output['piece_quantity']);
        $this->assertSame('バラ', $output['unit_label']);
    }

    public function test_piece_quantity_is_converted_to_six_pack_case_quantity_for_output(): void
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:4901411004754' => (object) ['quantity' => 6],
        ]);
        $this->setPrivateProperty($resolver, 'purchaseUnitPriceCache', [
            999006 => 215.0,
        ]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => QuantityType::PIECE,
            'order_quantity' => 25,
            'ordering_code' => '4901411004754',
            'purchase_unit_price' => 215,
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 24,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame(2, $output['order_quantity']);
        $this->assertSame(2, $output['case_quantity']);
        $this->assertSame(0, $output['piece_quantity']);
        $this->assertSame('ケース', $output['unit_label']);
    }

    public function test_190_piece_quantity_is_converted_to_eight_cases_for_six_pack_ordering_code(): void
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:4901411004754' => (object) ['quantity' => 6],
        ]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => QuantityType::PIECE,
            'order_quantity' => 190,
            'ordering_code' => '4901411004754',
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 24,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame(4, $output['display_capacity']);
        $this->assertSame(8, $output['order_quantity']);
        $this->assertSame(8, $output['case_quantity']);
        $this->assertSame(0, $output['piece_quantity']);
        $this->assertSame('ケース', $output['unit_label']);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function sixPackResolver(): OrderOutputQuantityResolver
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:4901411004754' => (object) ['quantity' => 6],
        ]);
        $this->setPrivateProperty($resolver, 'purchaseUnitPriceCache', [
            999006 => 215.0,
        ]);

        return $resolver;
    }

    private function sixPackCandidate(QuantityType $quantityType, int $quantity, int $purchaseUnitPrice): WmsOrderCandidate
    {
        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => $quantityType,
            'order_quantity' => $quantity,
            'ordering_code' => '4901411004754',
            'purchase_unit_price' => $purchaseUnitPrice,
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 24,
        ]);

        return $candidate;
    }

    private function expectedSixPackCaseQuantityFromPieces(int $pieceQuantity): int
    {
        if ($pieceQuantity <= 0) {
            return 0;
        }

        return (int) ceil($pieceQuantity / 24);
    }
}
