<?php

/** Verify explicit tenant lifetimes without database or framework state. */

use App\Exceptions\MissingTenantContext;
use App\Services\Tenancy\TenantContext;

it('fails closed outside a tenant unit of work', function (): void {
    expect(fn () => (new TenantContext())->requireTeamId())->toThrow(MissingTenantContext::class);
});

it('restores nested contexts on return and exception', function (): void {
    $context = new TenantContext();
    $result = $context->run(10, function () use ($context): string {
        expect($context->requireTeamId())->toBe(10);
        expect($context->run(20, fn (): int => $context->requireTeamId()))->toBe(20);
        expect($context->requireTeamId())->toBe(10);
        expect(fn () => $context->run(30, fn () => throw new RuntimeException('synthetic')))->toThrow(RuntimeException::class);
        expect($context->requireTeamId())->toBe(10);

        return 'complete';
    });

    expect($result)->toBe('complete');
    expect(fn () => $context->requireTeamId())->toThrow(MissingTenantContext::class);
    expect(fn () => $context->run(40, fn () => throw new RuntimeException('synthetic')))->toThrow(RuntimeException::class);
    expect(fn () => $context->requireTeamId())->toThrow(MissingTenantContext::class);
});

it('rejects invalid tenant identifiers without changing its state', function (int $id): void {
    $context = new TenantContext();
    $context->run(10, function () use ($context, $id): void {
        expect(fn () => $context->run($id, fn (): bool => true))->toThrow(InvalidArgumentException::class);
        expect($context->requireTeamId())->toBe(10);
    });
})->with([0, -1]);
