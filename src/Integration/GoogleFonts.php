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
 * Google Fonts — the stylesheet is served from fonts.googleapis.com, the font
 * files from fonts.gstatic.com.
 *
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class GoogleFonts implements Integration
{
    public function name(): string
    {
        return 'googleFonts';
    }

    public function apply(Policy $policy, array $options = []): void
    {
        $policy
            ->add('style-src', 'https://fonts.googleapis.com')
            ->add('font-src', 'https://fonts.gstatic.com');
    }
}
