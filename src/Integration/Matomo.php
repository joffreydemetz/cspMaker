<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\CspMaker\Integration;

use JDZ\CspMaker\Policy;
use JDZ\CspMaker\Exception\CspMakerException;

/**
 * Matomo / Piwik analytics.
 *
 * matomo.js loads via script-src; the tracker POSTs via connect-src and falls
 * back to a tracking pixel (img-src); the opt-out form is an iframe (frame-src).
 * The host is caller-supplied so it works for any Matomo instance, not one
 * hardcoded domain.
 *
 * Options: ['host' => 'piwik.example.com']  (or a bare first positional value)
 *
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class Matomo implements Integration
{
    public function name(): string
    {
        return 'matomo';
    }

    public function apply(Policy $policy, array $options = []): void
    {
        $host = (string) ($options['host'] ?? $options[0] ?? '');
        $host = trim($host);

        if ('' === $host) {
            throw new CspMakerException('The matomo integration requires a host, e.g. ["host" => "piwik.example.com"].');
        }

        $policy
            ->add('script-src', $host)
            ->add('img-src', $host)
            ->add('connect-src', $host)
            ->add('frame-src', $host);
    }
}
