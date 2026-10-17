<?php

namespace App\Enums;

/** DATA-12: tasks.task_status. */
enum TaskStatus: string
{
    use EnumValues;

    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Overdue = 'overdue';
}
