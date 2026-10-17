<?php

return [
    /*
    | SEC-10 matter-level access.
    |   firm        every user sees every matter their module permissions allow (the historical behaviour; default so an
    |               upgrade never silently hides data from an existing firm)
    |   restricted  a user sees a matter only if they are assigned to it, on its team (attorneys table), created it, or hold an
    |               unexpired explicit grant. Users with the `access-all-matters` permission (admin by default) see everything.
    */
    'access' => env('MATTER_ACCESS', 'firm'),
];
