<?php

namespace App\Http\Middleware;

class StartSession extends \Illuminate\Session\Middleware\StartSession
{
    protected function storeCurrentUrl($request, $session)
    {
        if ($request->routeIs('password.*')) {
            return;
        }
        parent::storeCurrentUrl($request, $session);
    }
}
