@extends('errors.layout', [
    'code'    => 419,
    'title'   => 'Session expired',
    'message' => 'Your session has expired. Please sign in again to continue.',
    // A 419 almost always means the session is gone — never offer a
    // dashboard link that would just bounce to the login page anyway.
    'signedIn' => false,
])
