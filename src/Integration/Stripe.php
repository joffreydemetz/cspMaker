<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\CspMaker\Integration;

use JDZ\CspMaker\Policy;

/**
 * Stripe.js checkout / elements.
 *
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class Stripe implements Integration
{
    public function name(): string
    {
        return 'stripe';
    }

    public function apply(Policy $policy, array $options = []): void
    {
        $policy
            ->add('script-src', 'https://js.stripe.com')
            ->add('frame-src', ['https://js.stripe.com', 'https://hooks.stripe.com'])
            ->add('connect-src', 'https://api.stripe.com');
    }
}
