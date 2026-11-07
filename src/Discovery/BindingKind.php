<?php

declare(strict_types=1);

namespace Pront\Discovery;

/**
 * What a condition parameter receives (ADR-0009 section 4, Stage 1 subset of ADR-0014 section 3).
 */
enum BindingKind
{
    /** The method's return value: `$return` or `$__return`. */
    case Return;

    /** The receiver of an instance method: `$self` or `$__self`. */
    case Receiver;

    /** The method parameter of the same name. */
    case Parameter;
}
