<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($hydrateEntry = collect(glob(public_path('build/rsc/entry.hydrate-*.js')))->first())
    @if ($hydrateEntry)
        <link rel="modulepreload" href="/build/rsc/{{ basename($hydrateEntry) }}">
    @endif
</head>

<body>
    <div id="rsc-root">{!! $body !!}</div>

    <script>
        window.__RSC_INITIAL__ = {!! $initialJson !!};
    </script>

    {!! $scripts !!}
</body>

</html>
