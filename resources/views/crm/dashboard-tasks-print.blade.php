<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            color: #0f172a;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            margin: 0;
            background: #f8fafc;
        }

        main {
            margin: 0 auto;
            max-width: 900px;
            padding: 32px;
        }

        header {
            border-bottom: 1px solid #cbd5e1;
            margin-bottom: 24px;
            padding-bottom: 18px;
        }

        h1 {
            font-size: 28px;
            margin: 0;
        }

        .muted {
            color: #64748b;
            font-size: 13px;
        }

        .summary {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }

        .summary div {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 12px 14px;
        }

        .summary strong {
            display: block;
            font-size: 22px;
        }

        .task {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            margin-bottom: 12px;
            padding: 16px;
            break-inside: avoid;
        }

        .label {
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            color: #334155;
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 8px;
            text-transform: uppercase;
        }

        h2 {
            font-size: 16px;
            margin: 10px 0 4px;
        }

        p {
            margin: 0;
        }

        .description {
            margin-top: 10px;
            line-height: 1.5;
        }

        @media print {
            body {
                background: white;
            }

            main {
                max-width: none;
                padding: 0;
            }

            .no-print {
                display: none;
            }

            .task,
            .summary div {
                border-color: #cbd5e1;
            }
        }
    </style>
</head>
<body>
    <main>
        <p class="no-print" style="margin-bottom: 16px;">
            <button onclick="window.print()" style="border: 1px solid #cbd5e1; border-radius: 10px; background: white; cursor: pointer; font-weight: 700; padding: 10px 14px;">
                Print
            </button>
        </p>

        <header>
            <p class="muted">CRM Dashboard</p>
            <h1>Today’s Task List</h1>
            <p class="muted">
                Printed {{ $printedAt->format('M j, Y g:i A') }}
            </p>

            <div class="summary">
                <div>
                    <strong>{{ $tasks->count() }}</strong>
                    <span class="muted">open tasks</span>
                </div>

                <div>
                    <strong>{{ $overdueCount }}</strong>
                    <span class="muted">overdue</span>
                </div>

                <div>
                    <strong>{{ $dueTodayCount }}</strong>
                    <span class="muted">due today</span>
                </div>
            </div>
        </header>

        @forelse($taskItems as $task)
            <article class="task">
                <span class="label">{{ $task['label'] ?? 'Task' }}</span>

                <h2>{{ $task['title'] }}</h2>

                @if(filled($task['subtitle'] ?? null))
                    <p class="muted">{{ $task['subtitle'] }}</p>
                @endif

                @if(filled($task['description'] ?? null))
                    <p class="description">{{ $task['description'] }}</p>
                @endif
            </article>
        @empty
            <article class="task">
                <h2>No tasks need your attention today.</h2>
                <p class="muted">The manual follow-up list is clear.</p>
            </article>
        @endforelse
    </main>
</body>
</html>
