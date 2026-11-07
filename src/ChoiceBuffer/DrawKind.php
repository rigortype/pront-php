<?php

declare(strict_types=1);

namespace Pront\ChoiceBuffer;

/**
 * The draw kinds of ADR-0002 section 2. The case order is only used to compare draws of different
 * kinds (see `Simplicity::compareDraws()`).
 */
enum DrawKind
{
    case Selector;
    case Bool;
    case Nullable;
    case Index;
    case Length;
    case Int;
    case Character;
    case Float;
}
