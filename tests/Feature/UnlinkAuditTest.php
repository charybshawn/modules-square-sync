<?php

use App\Models\Product;
use App\Models\User;
use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Models\SquareObjectMapping;

/*
 * Unlinking a product records through the AuditLog contract; how the host
 * stores it is the host's to test.
 */
it('records unlinking a product from Square through the AuditLog contract', function () {
    $audit = Mockery::spy(AuditLog::class);
    app()->instance(AuditLog::class, $audit);

    $product = Product::factory()->create(['title' => 'Pea Shoots']);
    $mapping = SquareObjectMapping::factory()->forItem($product->id)->create();

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->post(route('admin.square.unlink', $mapping))
        ->assertRedirect();

    $audit->shouldHaveReceived('record')->withArgs(fn (...$args) => $args[0] === 'square.product_unlinked' && $args[2] === $product->id)->once();
    expect(SquareObjectMapping::count())->toBe(0);
});
