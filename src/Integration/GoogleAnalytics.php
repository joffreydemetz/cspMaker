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
 * Google Analytics (GA4 / gtag.js, loaded through Google Tag Manager).
 *
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class GoogleAnalytics implements Integration
{
    public function name(): string
    {
        return 'googleAnalytics';
    }

    public function apply(Policy $policy, array $options = []): void
    {
        $policy
            ->add('script-src', ['https://www.googletagmanager.com', 'https://www.google-analytics.com'])
            ->add('img-src', ['https://www.google-analytics.com', 'https://www.googletagmanager.com'])
            ->add('connect-src', ['https://www.google-analytics.com', 'https://analytics.google.com', 'https://www.googletagmanager.com']);
    }
}
