<?php

namespace App\Modules\Core\Contracts\Contacts;

interface ContactResultActionContributor
{
    /**
     * @return iterable<int, \App\Modules\Core\Data\Contacts\ContactResultAction>
     */
    public function actions(): iterable;
}