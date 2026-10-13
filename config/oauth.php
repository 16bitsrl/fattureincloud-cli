<?php

/*
|--------------------------------------------------------------------------
| Built-in OAuth app
|--------------------------------------------------------------------------
|
| The 16bit OAuth app used by `fic auth:login`. It is a public client: it
| has no client secret in the binary and signs in with the device code
| flow. Users can bring their own app with --client-id/--client-secret
| (authorization code flow with a local callback), and the values can be
| overridden with FIC_CLIENT_ID/FIC_CLIENT_SECRET.
|
*/

return [
    'client_id' => env('FIC_CLIENT_ID', 'm0FcQvQzJgkCegfxhaEqjESuQvZ9KONR'),
    'client_secret' => env('FIC_CLIENT_SECRET', ''),
    'redirect_port' => 8511,
];
