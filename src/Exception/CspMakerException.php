<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\CspMaker\Exception;

/**
 * Thrown for invalid CSP input — unknown directives, unknown integrations, etc.
 *
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
class CspMakerException extends \InvalidArgumentException {}
