<?php

declare(strict_types=1);

/**
 * Closes the small residual coverage gaps left in
 * src/Models/PaymentTransaction.php after PaymentTransactionTest.php,
 * PaymentTransactionCompleteTest.php, PaymentTransactionCoverageTest.php and
 * PaymentTransactionFallbackTest.php:
 *
 *  - setAttribute()'s pre-Laravel-11 branch for BOTH 'metadata' and
 *    'customer' (the existing fallback tests only ever exercise the
 *    isSuccessful()/isFailed()/isPending() *normal* path, never the legacy
 *    manual json_encode() branch, and never setAttribute() at all).
 *  - The catch(Throwable) fallback inside isSuccessful()/isFailed()/
 *    isPending(): the existing "falls back to static when container
 *    unavailable" tests never actually make the container throw, so that
 *    catch block itself was never executed.
 *
 * See tests/Unit/SubscriptionTransactionTest.php for the app()-version
 * shadowing technique used below and why the equivalent getAttribute()
 * legacy branch is intentionally left uncovered (it's unreachable: casts()
 * always registers a cast for 'metadata'/'customer', so Eloquent's own array
 * cast already decodes the raw value before this class's getAttribute() ever
 * inspects it).
 */

namespace KenDeNigerian\PayZephyr\Models {
    if (! function_exists(__NAMESPACE__.'\\app')) {
        function app($abstract = null, array $parameters = [])
        {
            if ($abstract === null && array_key_exists('__pz_fake_laravel_version', $GLOBALS)) {
                return new class($GLOBALS['__pz_fake_laravel_version'])
                {
                    public function __construct(private string $version) {}

                    public function version(): string
                    {
                        return $this->version;
                    }
                };
            }

            return \app($abstract, $parameters);
        }
    }
}

namespace {

    use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;
    use KenDeNigerian\PayZephyr\Models\PaymentTransaction;

    // ==================== Legacy (<11.0) setAttribute coverage ====================

    test('setAttribute json-encodes metadata arrays on pre-Laravel-11 versions', function () {
        $GLOBALS['__pz_fake_laravel_version'] = '10.48.0';

        try {
            $model = new PaymentTransaction;
            $model->setAttribute('metadata', ['order_id' => 1]);

            expect($model->getAttributes()['metadata'])->toBe(json_encode(['order_id' => 1]));
        } finally {
            unset($GLOBALS['__pz_fake_laravel_version']);
        }
    });

    test('setAttribute json-encodes customer arrays on pre-Laravel-11 versions', function () {
        $GLOBALS['__pz_fake_laravel_version'] = '10.48.0';

        try {
            $model = new PaymentTransaction;
            $model->setAttribute('customer', ['name' => 'Ken']);

            expect($model->getAttributes()['customer'])->toBe(json_encode(['name' => 'Ken']));
        } finally {
            unset($GLOBALS['__pz_fake_laravel_version']);
        }
    });

    test('setAttribute stores null metadata/customer as-is on pre-Laravel-11 versions', function () {
        $GLOBALS['__pz_fake_laravel_version'] = '10.48.0';

        try {
            $model = new PaymentTransaction;
            $model->setAttribute('metadata', null);
            $model->setAttribute('customer', null);

            expect($model->getAttributes()['metadata'])->toBeNull()
                ->and($model->getAttributes()['customer'])->toBeNull();
        } finally {
            unset($GLOBALS['__pz_fake_laravel_version']);
        }
    });

    test('setAttribute stores non-array metadata/customer values as-is on pre-Laravel-11 versions', function () {
        $GLOBALS['__pz_fake_laravel_version'] = '10.48.0';

        try {
            $model = new PaymentTransaction;
            $model->setAttribute('metadata', 'already-a-string');
            $model->setAttribute('customer', 'already-a-string');

            expect($model->getAttributes()['metadata'])->toBe('already-a-string')
                ->and($model->getAttributes()['customer'])->toBe('already-a-string');
        } finally {
            unset($GLOBALS['__pz_fake_laravel_version']);
        }
    });

    test('setAttribute for unrelated keys delegates to the parent implementation on pre-Laravel-11 versions', function () {
        $GLOBALS['__pz_fake_laravel_version'] = '10.48.0';

        try {
            $model = new PaymentTransaction;
            $model->setAttribute('status', 'success');

            expect($model->getAttributes()['status'])->toBe('success');
        } finally {
            unset($GLOBALS['__pz_fake_laravel_version']);
        }
    });

    // ==================== StatusNormalizer container-failure fallback coverage ====================

    test('isSuccessful falls back to StatusNormalizer::normalizeStatic when container resolution throws', function () {
        app()->bind(StatusNormalizerInterface::class, function () {
            throw new RuntimeException('container blew up');
        });

        $model = new PaymentTransaction(['status' => 'succeeded']);

        expect($model->isSuccessful())->toBeTrue();
    });

    test('isFailed falls back to StatusNormalizer::normalizeStatic when container resolution throws', function () {
        app()->bind(StatusNormalizerInterface::class, function () {
            throw new RuntimeException('container blew up');
        });

        $model = new PaymentTransaction(['status' => 'declined']);

        expect($model->isFailed())->toBeTrue();
    });

    test('isPending falls back to StatusNormalizer::normalizeStatic when container resolution throws', function () {
        app()->bind(StatusNormalizerInterface::class, function () {
            throw new RuntimeException('container blew up');
        });

        $model = new PaymentTransaction(['status' => 'processing']);

        expect($model->isPending())->toBeTrue();
    });

    test('isSuccessful returns false for an unrecognized status even when the container throws', function () {
        app()->bind(StatusNormalizerInterface::class, function () {
            throw new RuntimeException('container blew up');
        });

        $model = new PaymentTransaction(['status' => 'totally_unknown_status']);

        expect($model->isSuccessful())->toBeFalse();
    });

}
