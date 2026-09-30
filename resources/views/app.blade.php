<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="author" content="Raúl Noguera">
        <meta name="generator" content="ServiPos Tickets · desarrollado por Raúl Noguera">
        @unless (\Illuminate\Support\Facades\Vite::isRunningHot())
            <meta http-equiv="Content-Security-Policy" content="{{ \App\Http\Middleware\SecurityHeaders::policy(request()->isSecure(), meta: true) }}">
        @endunless
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#00194c">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="ServiPos">
        <title>ServiPos Tickets</title>
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="icon" type="image/png" href="/favicon.png?v=2">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png?v=2">
        @fonts
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>
