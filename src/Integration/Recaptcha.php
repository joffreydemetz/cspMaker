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
 * Google reCAPTCHA (v2 / v3).
 *
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class Recaptcha implements Integration
{
    public function name(): string
    {
        return 'recaptcha';
    }

    public function apply(Policy $policy, array $options = []): void
    {
        $policy
            ->add('script-src', ['https://www.google.com', 'https://www.gstatic.com'])
            ->add('frame-src', 'https://www.google.com');
    }
}
