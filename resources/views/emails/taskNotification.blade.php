<!DOCTYPE html>
<html>
<head>
    <title>Task Notification</title>
</head>
<body>
<h1>Task {{ $task->title }}!</h1>

<p>{{ $task->description }}</p>
<p>Due: {{ $task->due_date }}</p>
</body>
</html>
