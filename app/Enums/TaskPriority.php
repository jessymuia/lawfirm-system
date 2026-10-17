<?php

namespace App\Enums;

/** DATA-12: tasks.priority (a free string column since 2025; constrained again here). */
enum TaskPriority: string
{
    use EnumValues;

    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
