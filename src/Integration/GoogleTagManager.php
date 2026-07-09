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
 * Google Tag Manager container script.
 *
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class GoogleTagManager implements Integration
{
    public function name(): string
    {
        return 'googleTagManager';
    }

    public function apply(Policy $policy, array $options = []): void
    {
        $policy
            ->add('script-src', 'https://www.googletagmanager.com')
            ->add('img-src', 'https://www.googletagmanager.com')
            ->add('connect-src', 'https://www.googletagmanager.com');
    }
}
