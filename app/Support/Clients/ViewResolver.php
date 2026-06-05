<?php

namespace App\Support\Clients;

class ViewResolver
{
    public static function resolve(string $view): string
    {
        $client = 'client::'.$view;

        return view()->exists($client)
            ? $client
            : $view;
    }
}