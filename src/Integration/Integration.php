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
 * A reusable third-party recipe.
 *
 * An integration encodes every directive a given service touches, so callers
 * whitelist "Matomo" or "Google Fonts" once instead of hand-listing script-src
 * + img-src + connect-src and forgetting one.
 *
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
interface Integration
{
    /** Stable lookup key, e.g. "matomo". */
    public function name(): string;

    /**
     * Apply this integration's directives to the policy.
     *
     * @param array<string,mixed> $options  recipe-specific (e.g. ['host' => '…'])
     */
    public function apply(Policy $policy, array $options = []): void;
}
