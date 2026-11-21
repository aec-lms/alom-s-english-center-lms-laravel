<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Launching learning tool') }}</title>
</head>
<body>
    <main>
        <p>{{ __('Securely connecting to your learning tool…') }}</p>
        <form id="lti-launch" method="post" action="{{ $redirectUri }}">
            <input type="hidden" name="id_token" value="{{ $idToken }}">
            <input type="hidden" name="state" value="{{ $state }}">
            <button type="submit">{{ __('Continue') }}</button>
        </form>
    </main>
    <script nonce="{{ $cspNonce ?? '' }}">
        document.getElementById('lti-launch').submit();
    </script>
</body>
</html>
