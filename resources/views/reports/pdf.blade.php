<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @page {
            margin: 34px 30px 44px;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #182433;
        }
        h1 {
            font-size: 20px;
            color: #147e83;
            margin: 0 0 5px;
        }
        h2 {
            font-size: 14px;
            margin: 8px 0;
        }
        h3 {
            font-size: 11px;
            margin-top: 18px;
        }
        .meta {
            color: #526477;
            line-height: 1.6;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0 15px;
            table-layout: fixed;
        }
        th {
            background: #147e83;
            color: white;
            text-align: left;
            padding: 6px;
        }
        td {
            border-bottom: 1px solid #dce1e7;
            padding: 5px;
            overflow-wrap: anywhere;
            word-wrap: break-word;
        }
        thead {
            display: table-header-group;
        }
        tr {
            page-break-inside: avoid;
        }
        .number {
            text-align: right;
        }
        .notes {
            font-size: 8px;
            color: #526477;
        }
    </style>
</head>
<body>
    <h1>Sistema Web de NEXO PARK</h1>
    <h2>{{ $report['title'] }}</h2>
    
    <div class="meta">
        Emisión: {{ $report['generated_at'] }} · America/La_Paz<br>
        @foreach($report['filter_labels'] as $label => $value)
            {{ $label }}: {{ $value }}{{ $loop->last ? '' : ' | ' }}
        @endforeach
    </div>

    <h3>Resumen y totales</h3>
    <table>
        <thead>
            <tr>
                <th>Indicador</th>
                <th>Valor</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report['summary'] as $metric)
                <tr>
                    <td>{{ $metric['label'] }}</td>
                    <td class="number">
                        {{ $metric['value'] === null ? 'Sin base de comparación' : ($metric['format'] === 'money' ? 'Bs '.number_format((float) $metric['value'], 2, '.', ',') : $metric['value'].($metric['format'] === 'percent' ? '%' : '')) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @foreach($report['tables'] as $table)
        <h3>{{ $table['title'] }}</h3>
        <table>
            <thead>
                <tr>
                    @foreach($table['columns'] as $column)
                        <th>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($table['rows'] as $row)
                    <tr>
                        @foreach($table['columns'] as $column)
                            <td class="{{ in_array($column['format'], ['number', 'money', 'percent']) ? 'number' : '' }}">
                                {{ ($row[$column['key']] ?? null) === null ? '—' : ($column['format'] === 'money' ? 'Bs '.number_format((float) $row[$column['key']], 2, '.', ',') : $row[$column['key']]) }}
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($table['columns']) }}">Sin registros para estos filtros.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @endforeach

    <div class="notes">
        @foreach($report['notes'] as $note)
            <p>{{ $note }}</p>
        @endforeach
    </div>
</body>
</html>