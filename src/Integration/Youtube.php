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
 * YouTube embeds (privacy-enhanced youtube-nocookie.com and the standard host).
 *
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class Youtube implements Integration
{
    public function name(): string
    {
        return 'youtube';
    }

    public function apply(Policy $policy, array $options = []): void
    {
        $policy
            ->add('frame-src', ['https://www.youtube-nocookie.com', 'https://www.youtube.com'])
            ->add('img-src', 'https://i.ytimg.com');
    }
}
