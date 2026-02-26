<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @yield('head')
</head>
<body>
    <div id="rsc-root">{!! $body !!}</div>

    <script>
        window.__RSC_INITIAL__ = {
            url: @json($url),
            component: @json($component),
            version: @json($version)
        };
    </script>

    @rscScripts($rscPayload, $clientChunks)
</body>
</html>
